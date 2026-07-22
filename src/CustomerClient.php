<?php

declare(strict_types=1);

namespace Allus\CompanyData;

use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Errors\ConfigError;
use Allus\CompanyData\Errors\ValidationError;
use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\Model\Change;
use Allus\CompanyData\Model\CustomerConnection;
use Allus\CompanyData\Model\Document;
use Allus\CompanyData\Model\FlowRun;
use Allus\CompanyData\Pump\Pump;
use Allus\CompanyData\Webhooks\Webhooks;
use phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;
use phpseclib3\Crypt\RSA\PublicKey as RSAPublicKey;

/**
 * The CUSTOMER-role client (b2b, #168).
 *
 * `CustomerClient` is what a connecting company uses to consume and answer another
 * company's service over its `acct_*` credentials: list company↔company connections,
 * provide/edit typed consent answers, read (and decrypt) issued documents, run contract
 * flows, drain the account change feed, and verify account-level webhooks. It reuses the
 * same crash-safe {@see Pump}, webhook helpers, and hybrid-crypto core as the service
 * {@see Client}.
 *
 * NO sign/accept methods (spec D6): signing/accepting a contract is a deliberate human
 * step-up that stays portal-only; a machine `acct_*` token is rejected by the API for
 * those routes.
 */
final class CustomerClient
{
    private const CONN = '/api/company-connections';
    private const CONSENTS = '/api/company-connections/consents';
    private const CUSTOMER_CHANGES = '/api/customer/changes';
    private const KEYS = '/api/keys';

    private readonly HttpClient $http;
    /** OAEP-SHA256 account key — decrypts document/field/change values (the person-value contract). */
    private readonly ?RSAPrivateKey $accountKey;
    /** OAEP-SHA1 account key — unwraps the account-key webhook envelope (OpenSSL default). */
    private readonly ?RSAPrivateKey $accountEnvelopeKey;
    /** @var array<string,?RSAPublicKey> */
    /**
     * #344 review pass 3 — why this SDK has NO generation counter, unlike Go/Java/C#/TS/Python.
     *
     * The lost-invalidation race needs a window between the cache check and the store in which
     * `invalidatePublicKey` can run. PHP's request-scoped, single-threaded execution model has no
     * such window: there are no threads, no event loop and no `await`, so nothing else in this
     * process can run while the HTTP fetch below is in progress. The counter would be dead code.
     *
     * This exemption is a property of the RUNTIME, not of this code. If this client is ever
     * wrapped in an async runtime (Swoole, Fibers, ReactPHP) or shared across threads, it inherits
     * the race and MUST gain the same generation counter the other five SDKs carry.
     */
    private array $pubkeyCache = [];
    /** @var array<string,?RSAPublicKey> */
    private array $serviceKeyCache = [];
    /**
     * "companyCode/serviceCode" → {request_field_id: field_type}, resolved from the
     * connect-screen lookup for typed-answer validation (#302).
     *
     * @var array<string,array<string,string>>
     */
    private array $requestTypeCache = [];
    private ?Pump $pump = null;

    public function __construct(
        private readonly Config $config,
        ?HttpClient $http = null,
    ) {
        if (($config->customerClientId ?? null) === null || ($config->customerClientSecret ?? null) === null) {
            throw new ConfigError(
                'CustomerClient requires customer_client_id + customer_client_secret '
                . '(load with Config::fromCustomerFile / fromCustomerEnv)'
            );
        }
        // The transport authenticates as the acct_* client — hand HttpClient a config
        // whose clientId/secret are the customer pair.
        $httpConfig = new Config(
            apiUrl: $config->apiUrl,
            clientId: $config->customerClientId,
            clientSecret: $config->customerClientSecret,
            customerClientId: $config->customerClientId,
            customerClientSecret: $config->customerClientSecret,
            accountPrivateKey: $config->accountPrivateKey,
            accountPassphrase: $config->accountPassphrase,
            webhooks: $config->webhooks,
            cacheDir: $config->cacheDir,
            format: $config->format,
            webhookBearerToken: $config->webhookBearerToken,
            webhookBasic: $config->webhookBasic,
            webhookHeader: $config->webhookHeader,
            webhookAuthNone: $config->webhookAuthNone,
        );
        $this->http = $http ?? new HttpClient($httpConfig);
        // ACCOUNT private key — decrypts received documents/flow copies (loaded once).
        // Field/document values use the OAEP-SHA256 person-value contract; the webhook
        // envelope uses OpenSSL-default OAEP-SHA1 — load BOTH forms of the same key.
        $pem = @file_get_contents((string) $config->accountPrivateKey);
        if ($pem === false) {
            throw new ConfigError('could not read account_private_key PEM: ' . $config->accountPrivateKey);
        }
        $this->accountKey = Crypto::loadPrivateKey($pem, $config->accountPassphrase ?? '');
        $this->accountEnvelopeKey = Webhooks::loadAccountKey($config);
    }

    public static function fromConfig(string $path): self
    {
        return new self(Config::fromCustomerFile($path));
    }

    public static function fromEnv(): self
    {
        return new self(Config::fromCustomerEnv());
    }

    // ── connections ─────────────────────────────────────────────────────────────

    /** @return list<CustomerConnection> */
    public function connections(): array
    {
        return CustomerConnection::listFromApi($this->http->get(self::CONN));
    }

    public function connection(string $id): CustomerConnection
    {
        $body = $this->http->get(self::CONN . '/' . $id);
        return CustomerConnection::fromApi(is_array($body) ? $body : []);
    }

    // ── consents (typed answers) ─────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function pendingConsents(): array
    {
        $body = $this->http->get(self::CONSENTS);
        if (is_array($body)) {
            $items = $body['consents'] ?? $body['items'] ?? (array_is_list($body) ? $body : []);
            return array_values(array_filter($items, 'is_array'));
        }
        return [];
    }

    /**
     * Answer a consent's request rows by TYPING values (encrypted to the target service key).
     *
     * Each value is validated against its request row's field type (resolved from the
     * connect-screen lookup, cached per service) before encryption.
     *
     * @param list<array{request_field_id:string,value:string,kind?:string}> $answers
     */
    public function provideConsent(string $consentId, array $answers, string $companyCode, string $serviceCode): mixed
    {
        $decisions = $this->encryptTyped($answers, $companyCode, $serviceCode);
        return $this->http->post(self::CONSENTS . '/' . $consentId . '/provide', ['decisions' => $decisions]);
    }

    public function declineConsent(string $consentId): mixed
    {
        return $this->http->post(self::CONSENTS . '/' . $consentId . '/decline');
    }

    /** @param list<array{request_field_id:string,value:string,kind?:string}> $answers */
    public function editAnswers(string $connectionId, string $serviceLinkId, array $answers, string $companyCode, string $serviceCode): mixed
    {
        $decisions = $this->encryptTyped($answers, $companyCode, $serviceCode);
        return $this->http->put(self::CONN . '/' . $connectionId . '/services/' . $serviceLinkId . '/mappings', ['decisions' => $decisions]);
    }

    // ── documents (account-key decrypt; NO sign/accept — D6) ──────────────────────

    /** @return list<Document> */
    public function documents(CustomerConnection $connection): array
    {
        $decrypt = fn (array|string $w): string => $this->decryptAccount($w);
        $out = [];
        foreach ($connection->services as $svc) {
            foreach (($svc->raw['documents'] ?? []) as $d) {
                if (is_array($d)) {
                    $out[] = Document::fromApi($d, $decrypt);
                }
            }
        }
        foreach (($connection->raw['documents'] ?? []) as $d) {
            if (is_array($d)) {
                $out[] = Document::fromApi($d, $decrypt);
            }
        }
        return $out;
    }

    public function documentFile(string $connectionId, string $documentId): mixed
    {
        $body = $this->http->get(self::CONN . '/' . $connectionId . '/documents/' . $documentId . '/file');
        if (is_array($body) && ($body['encrypted'] ?? false) && isset($body['value'])) {
            return json_decode($this->decryptAccount($body['value']), true, flags: JSON_THROW_ON_ERROR);
        }
        if (is_array($body) && ($body['_enc'] ?? null) === 1) {
            return json_decode($this->decryptAccount($body), true, flags: JSON_THROW_ON_ERROR);
        }
        return $body;
    }

    public function cancelDocument(string $connectionId, string $documentId, ?string $note = null): mixed
    {
        return $this->http->post(
            self::CONN . '/' . $connectionId . '/documents/' . $documentId . '/cancel',
            $note !== null ? ['note' => $note] : null,
        );
    }

    // ── contract flows ────────────────────────────────────────────────────────────

    /** @return list<FlowRun> */
    public function flowRuns(string $connectionId): array
    {
        $body = $this->http->get(self::CONN . '/' . $connectionId . '/flow-runs');
        $items = is_array($body) ? ($body['runs'] ?? (array_is_list($body) ? $body : [])) : [];
        $out = [];
        foreach ($items as $o) {
            if (is_array($o)) {
                $out[] = FlowRun::fromApi($o);
            }
        }
        return $out;
    }

    public function flowRun(string $connectionId, string $runId): FlowRun
    {
        $body = $this->http->get(self::CONN . '/' . $connectionId . '/flow-runs/' . $runId);
        return FlowRun::fromApi(is_array($body) ? $body : []);
    }

    /** @param array<string,mixed> $body */
    public function submitFlowAnswers(string $connectionId, string $runId, array $body): mixed
    {
        return $this->http->post(self::CONN . '/' . $connectionId . '/flow-runs/' . $runId . '/answers', $body);
    }

    public function declineFlowRun(string $connectionId, string $runId): mixed
    {
        return $this->http->post(self::CONN . '/' . $connectionId . '/flow-runs/' . $runId . '/decline');
    }

    /**
     * Encrypt one answer value for one flow party per the P4 key rule.
     *
     * @param array{user_id:string,type?:string,is_owner?:bool} $party
     * @return array<string,mixed>
     */
    public function encryptFlowAnswer(string $plaintext, array $party, string $companyCode, string $serviceCode): array
    {
        $pub = ($party['is_owner'] ?? false)
            ? $this->serviceKey($companyCode, $serviceCode)
            : $this->batchKey((string) $party['user_id']);
        if ($pub === null) {
            throw new ConfigError('no public key available for party ' . ($party['user_id'] ?? '?'));
        }
        return Crypto::encryptForPublicKey($plaintext, $pub);
    }

    // ── change feed (P2 account feed) ─────────────────────────────────────────────

    public function pump(): Pump
    {
        if ($this->pump === null) {
            $this->pump = new Pump(
                $this->config,
                fn (int $limit): array => $this->fetchChanges($limit),
                fn (array $event): Change => $this->decryptChange($event),
            );
        }
        return $this->pump;
    }

    /** @return list<array<string,mixed>> */
    private function fetchChanges(int $limit): array
    {
        $body = $this->http->get(self::CUSTOMER_CHANGES, ['limit' => $limit]);
        $items = is_array($body) ? ($body['changes'] ?? (array_is_list($body) ? $body : [])) : [];
        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * #344 — drop a person's cached public key by user id. The changes feed calls this for you;
     * call it yourself when consuming `key_rotated` over a webhook (the verifier is static and has
     * no client instance).
     */
    public function invalidatePublicKey(string $userId): void
    {
        unset($this->pubkeyCache[$userId]);
    }

    /**
     * #411 — drop a SERVICE's cached public key, so the next answer/document encrypted to it
     * refetches. The mirror of {@see invalidatePublicKey}, in the service→customer direction.
     *
     * The changes feed calls this for you on a `service_key_rotated` event; call it yourself when
     * consuming that event over a webhook, passing the body's `company_share_code` and
     * `service_share_code` (the verifier is static and has no client instance).
     *
     * No generation counter here, for the same runtime reason recorded on `$pubkeyCache` above:
     * PHP's request-scoped, single-threaded model has no window between the cache check and the
     * store in which this method could run. Under an async runtime it inherits the race and needs
     * the counter the other five SDKs carry.
     */
    public function invalidateServiceKey(string $companyCode, string $serviceCode): void
    {
        unset($this->serviceKeyCache[$companyCode . '/' . $serviceCode]);
    }

    /** @param array<string,mixed> $event */
    private function decryptChange(array $event): Change
    {
        // #344: a service gets no pushes, so the feed is its only rotation signal — without this
        // the cached key (including a cached `null`) would outlive the rotation for the whole
        // process lifetime.
        // #344: the pull feed names it `event`; a raw webhook body names it `action` (and on
        // document rows `action` carries signed|accepted|cancelled instead) — so match either key.
        if (($event['event'] ?? null) === 'key_rotated' || ($event['action'] ?? null) === 'key_rotated') {
            $personId = $event['person_user_id'] ?? $event['person_id'] ?? null;
            if (is_string($personId) && $personId !== '') {
                $this->invalidatePublicKey($personId);
            }
        }
        // #411: a service this customer connects to replaced its keypair. Same either-key match:
        // the pull feed names it `event`, a raw webhook body names it `action`.
        if (($event['event'] ?? null) === 'service_key_rotated' || ($event['action'] ?? null) === 'service_key_rotated') {
            $companyCode = $event['company_share_code'] ?? null;
            $serviceCode = $event['service_share_code'] ?? null;
            if (is_string($companyCode) && $companyCode !== '' && is_string($serviceCode) && $serviceCode !== '') {
                $this->invalidateServiceKey($companyCode, $serviceCode);
            }
        }

        return Change::fromApi(
            $event,
            static fn (string $slug): ?string => null,
            fn (array|string $w): string => $this->decryptAccount($w),
        );
    }

    public function processChanges(callable $handler, array $options = []): void
    {
        $this->pump()->processChanges($handler, $options);
    }

    /** @return list<Change> */
    public function drainBatch(int $max = 100): array
    {
        return $this->pump()->drainBatch($max);
    }

    /** @return list<array<string,mixed>> */
    public function deadLetters(): array
    {
        return $this->pump()->deadLetters();
    }

    public function retryDeadLetters(callable $handler, array $options = []): int
    {
        return $this->pump()->retryDeadLetters($handler, $options);
    }

    // ── account-level webhook receiver helpers (config-driven) ────────────────────

    /** @param array<string,string> $headers */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return Webhooks::verify($rawBody, $headers, $this->config);
    }

    /** @param array<string,string> $headers */
    public function parseWebhook(string $rawBody, array $headers): Change
    {
        return Webhooks::parse(
            $rawBody,
            $headers,
            $this->config,
            static fn (string $slug): ?string => null,
            fn (array|string $w): string => $this->decryptAccount($w),
            null,
            $this->accountEnvelopeKey,
        );
    }

    /** @param array<string,string> $headers */
    public function handleWebhook(string $rawBody, array $headers): Change
    {
        return Webhooks::handle(
            $rawBody,
            $headers,
            $this->config,
            static fn (string $slug): ?string => null,
            fn (array|string $w): string => $this->decryptAccount($w),
            null,
            $this->accountEnvelopeKey,
        );
    }

    // ── internals ──────────────────────────────────────────────────────────────────

    private function decryptAccount(array|string $wrapper): string
    {
        if ($this->accountKey === null) {
            throw new ConfigError('account_private_key is required to decrypt this value');
        }
        return Crypto::decrypt($wrapper, $this->accountKey);
    }

    /**
     * Resolve {request_field_id: field_type} for a service from the connect-screen lookup,
     * cached per company/service. Best-effort — a lookup failure yields an empty map so
     * typed-answer validation is simply skipped (#302).
     *
     * @return array<string,string>
     */
    private function requestFieldTypes(string $companyCode, string $serviceCode): array
    {
        $key = $companyCode . '/' . $serviceCode;
        if (array_key_exists($key, $this->requestTypeCache)) {
            return $this->requestTypeCache[$key];
        }
        $out = [];
        try {
            $body = $this->http->get(self::CONN . '/lookup/' . $companyCode . '/' . $serviceCode);
            $rows = is_array($body) && isset($body['request_fields']) && is_array($body['request_fields'])
                ? $body['request_fields']
                : [];
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $rid = $r['id'] ?? null;
                $ftype = $r['field_type'] ?? $r['type'] ?? null;
                if ($rid !== null && $rid !== '' && $ftype !== null && $ftype !== '') {
                    $out[(string) $rid] = (string) $ftype;
                }
            }
        } catch (\Throwable) {
            // best-effort — a failed lookup skips validation
            $out = [];
        }
        $this->requestTypeCache[$key] = $out;
        return $out;
    }

    /**
     * @param list<array{request_field_id:string,value:string,kind?:string}> $answers
     * @return list<array<string,mixed>>
     */
    private function encryptTyped(array $answers, string $companyCode, string $serviceCode): array
    {
        $pub = $this->serviceKey($companyCode, $serviceCode);
        if ($pub === null) {
            throw new ConfigError("no service key for {$companyCode}/{$serviceCode}");
        }
        // #302: validate each typed answer against its request row's field type BEFORE
        // encryption. The type is resolved server-side from the connect-screen lookup
        // (cached per service); an answer whose type can't be resolved is skipped.
        $types = $this->requestFieldTypes($companyCode, $serviceCode);
        $out = [];
        foreach ($answers as $a) {
            $plain = (string) $a['value'];
            $ftype = $types[(string) $a['request_field_id']] ?? null;
            if ($ftype !== null && !FieldValidation::isFieldValueValid($ftype, $plain)) {
                throw new ValidationError((string) $a['request_field_id'], $ftype);
            }
            $out[] = [
                'request_field_id' => $a['request_field_id'],
                'kind' => $a['kind'] ?? 'typed',
                'value' => Crypto::encryptForPublicKey($plain, $pub),
            ];
        }
        return $out;
    }

    private function serviceKey(string $companyCode, string $serviceCode): ?RSAPublicKey
    {
        $key = $companyCode . '/' . $serviceCode;
        if (!array_key_exists($key, $this->serviceKeyCache)) {
            $body = $this->http->get(self::KEYS . '/' . $companyCode . '/' . $serviceCode);
            $spki = is_array($body) && isset($body['public_key']) ? (string) $body['public_key'] : null;
            $this->serviceKeyCache[$key] = $spki !== null && $spki !== '' ? Crypto::loadPublicKey($spki) : null;
        }
        return $this->serviceKeyCache[$key];
    }

    private function batchKey(string $userId): ?RSAPublicKey
    {
        if (!array_key_exists($userId, $this->pubkeyCache)) {
            $body = $this->http->post(self::KEYS . '/batch', ['user_ids' => [$userId]]);
            $spki = null;
            if (is_array($body) && isset($body['keys']) && is_array($body['keys'])) {
                $spki = isset($body['keys'][$userId]) ? (string) $body['keys'][$userId] : null;
            }
            $this->pubkeyCache[$userId] = $spki !== null && $spki !== '' ? Crypto::loadPublicKey($spki) : null;
        }
        return $this->pubkeyCache[$userId];
    }
}
