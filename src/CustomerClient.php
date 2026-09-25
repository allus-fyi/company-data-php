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
use Allus\CompanyData\Model\FieldTypes;
use Allus\CompanyData\Model\FlowRun;
use Allus\CompanyData\Model\PluginOptions;
use Allus\CompanyData\Model\PluginOutputs;
use Allus\CompanyData\Model\PluginPass;
use Allus\CompanyData\Model\PluginPicksInvalid;
use Allus\CompanyData\Pump\Pump;
use Allus\CompanyData\Webhooks\Webhooks;
use phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;
use phpseclib3\Crypt\RSA\PublicKey as RSAPublicKey;

/**
 * The CUSTOMER-role client (b2b).
 *
 * `CustomerClient` is what a connecting company uses to consume and answer another
 * company's service over its `acct_*` credentials: list company↔company connections,
 * provide/edit typed consent answers, read (and decrypt) issued documents, run contract
 * flows — generating the contract of a run whose last step it answered — drain the account
 * change feed, and verify account-level webhooks. It reuses the
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
    private const FIELD_TYPES = '/api/contact-field-types';

    private readonly HttpClient $http;
    /** OAEP-SHA256 account key — decrypts document/field/change values (the person-value contract). */
    private readonly ?RSAPrivateKey $accountKey;
    /** OAEP-SHA1 account key — unwraps the account-key webhook envelope (OpenSSL default). */
    private readonly ?RSAPrivateKey $accountEnvelopeKey;
    /** @var array<string,?RSAPublicKey> */
    /**
     * Why this SDK has NO generation counter for the public-key cache.
     *
     * The lost-invalidation race needs a window between the cache check and the store in which
     * `invalidatePublicKey` can run. PHP's request-scoped, single-threaded execution model has no
     * such window: there are no threads, no event loop and no `await`, so nothing else in this
     * process can run while the HTTP fetch below is in progress. The counter would be dead code.
     *
     * This exemption is a property of the RUNTIME, not of this code. If this client is ever
     * wrapped in an async runtime (Swoole, Fibers, ReactPHP) or shared across threads, it inherits
     * the race and MUST gain a generation counter.
     */
    private array $pubkeyCache = [];
    /** @var array<string,?RSAPublicKey> */
    private array $serviceKeyCache = [];
    /**
     * "companyCode/serviceCode" → {request_field_id: field_type}, resolved from the
     * connect-screen lookup for typed-answer validation.
     *
     * @var array<string,array<string,string>>
     */
    private array $requestTypeCache = [];
    /**
     * The field-type registry, fetched beside the request-field lookup and held for the life
     * of the client. A type it does not carry triggers ONE refetch; a type a refetch still does
     * not resolve is remembered in {@see $unresolvedTypes} and never asked for again.
     */
    private ?FieldTypes $fieldTypes = null;
    /** @var array<string,bool> */
    private array $unresolvedTypes = [];
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

    /**
     * Submit this party's turn. `$body` carries the already-encrypted per-party `answers`; use
     * {@see encryptFlowAnswer()} to build the copies and {@see checkFlowValue()} first to apply a
     * field's minimum and maximum.
     *
     * Every answer whose field's default reads another party's private source is marked
     * `source_private: true` before it is sent (the run is read once for the rule), so every later
     * reader treats it as private.
     *
     * @param array<string,mixed> $body
     */
    public function submitFlowAnswers(string $connectionId, string $runId, array $body): mixed
    {
        if (is_array($body['answers'] ?? null) && $body['answers'] !== []) {
            $view = $this->flowPartyView($this->flowRun($connectionId, $runId), false);
            $slugs = [];
            foreach ($body['answers'] as $a) {
                if (is_array($a) && is_string($a['slug'] ?? null)) {
                    $slugs[$a['slug']] = true;
                }
            }
            foreach ($body['answers'] as $i => $a) {
                if (is_array($a) && is_string($a['slug'] ?? null) && FlowPlugins::isDraftPrivate($view, $a['slug'], $slugs)) {
                    $body['answers'][$i]['source_private'] = true;
                }
            }
        }

        return $this->http->post(self::CONN . '/' . $connectionId . '/flow-runs/' . $runId . '/answers', $body);
    }

    /**
     * Refuse a value outside its flow field's `min`/`max` before {@see encryptFlowAnswer()} seals it.
     *
     * The bounds are computed over the live answer map: this company's own copies of the run's
     * answers (decrypted with the account key), overlaid with `$draft` — the current step's other
     * not-yet-submitted answers — and `$value` for `$slug`, plugin answers expanded, constants
     * computed. A bound that computes to null is no bound.
     *
     * @param array<string,mixed> $draft
     *
     * @throws ValidationError naming the bound, as the service Client does
     */
    public function checkFlowValue(FlowRun $run, string $slug, mixed $value, array $draft = []): void
    {
        $draft[$slug] = $value;
        $live = FlowPlugins::liveAnswerMap($this->flowPartyView($run), $draft);
        FlowPlugins::checkBounds($run->definition, $slug, $value, $live, $run->referenceDate);
    }

    /**
     * A pass for the plugin fields of the run's current step —
     * `POST /api/company-connections/{connectionId}/flow-runs/{runId}/plugin-pass`. Issued only
     * while the run awaits this company's party on that step.
     */
    public function pluginPass(string $connectionId, string $runId): PluginPass
    {
        return PluginPass::fromApi($this->http->post(self::CONN . '/' . $connectionId . '/flow-runs/' . $runId . '/plugin-pass'));
    }

    /**
     * The options of one block of the current step's plugin field `$slug`. Same contract as the
     * service {@see Client::pluginOptions()}, with a leading `$connectionId`; the inputs are read
     * from this company's own copies of the run's answers overlaid with `$draft`.
     *
     * @param array<string,string> $picks
     * @param array<string,mixed>  $values
     * @param array<string,mixed>  $draft
     */
    public function pluginOptions(string $connectionId, string $runId, string $slug, string $block, string $query = '', array $picks = [], array $values = [], array $draft = []): PluginOptions
    {
        $run = $this->flowRun($connectionId, $runId);
        $pass = $this->pluginPass($connectionId, $runId);
        $call = FlowPlugins::prepareCall($this->flowPartyView($run), $pass, $slug, $draft);
        $reply = FlowPlugins::callPlugin($pass, fn (): PluginPass => $this->pluginPass($connectionId, $runId), $call, [
            'op' => 'options',
            'block' => $block,
            'query' => $query,
            'picks' => $picks,
            'values' => $values,
        ]);

        return PluginOptions::fromReply($reply);
    }

    /**
     * The outputs of the current step's plugin field `$slug` for `$picks` and `$values`, or
     * {@see PluginPicksInvalid}. Same contract as the service {@see Client::pluginOutputs()}, with a
     * leading `$connectionId`.
     *
     * @param array<string,string> $picks
     * @param array<string,mixed>  $values
     * @param array<string,mixed>  $draft
     */
    public function pluginOutputs(string $connectionId, string $runId, string $slug, array $picks = [], array $values = [], array $draft = []): PluginOutputs|PluginPicksInvalid
    {
        $run = $this->flowRun($connectionId, $runId);
        $pass = $this->pluginPass($connectionId, $runId);
        $call = FlowPlugins::prepareCall($this->flowPartyView($run), $pass, $slug, $draft);
        $reply = FlowPlugins::callPlugin($pass, fn (): PluginPass => $this->pluginPass($connectionId, $runId), $call, [
            'op' => 'outputs',
            'picks' => $picks,
            'values' => $values,
        ]);

        return FlowPlugins::outputsResult($reply);
    }

    /**
     * This company's view of a run. It is bound to the party that owns the current step — the only
     * step it answers or calls a plugin on — and reads its own answer copies with the account key
     * (`$withAnswers` false reads none: the privacy rule needs only the graph and the lists).
     *
     * @return array{definition: array<string,mixed>, currentNode: ?string, referenceDate: ?string, stored: array<string,mixed>, privateSlugs: ?list<string>, ownPartyKeys: list<string>}
     */
    private function flowPartyView(FlowRun $run, bool $withAnswers = true): array
    {
        $ownUid = null;
        foreach ((is_array($run->definition['nodes'] ?? null) ? $run->definition['nodes'] : []) as $n) {
            if (is_array($n) && ($n['key'] ?? null) === $run->currentNode && isset($n['party'])) {
                $ownUid = $run->bindings[(string) $n['party']] ?? null;
            }
        }
        $own = [];
        $stored = [];
        if ($ownUid !== null && $ownUid !== '') {
            foreach ($run->bindings as $key => $uid) {
                if ($uid === $ownUid) {
                    $own[] = (string) $key;
                }
            }
            foreach ($withAnswers ? $run->answers : [] as $row) {
                if (($row['for_user_id'] ?? null) !== $ownUid) {
                    continue;
                }
                $slug = $row['slug'] ?? null;
                $value = $row['value'] ?? null;
                if (is_string($slug) && (is_string($value) || is_array($value))) {
                    $stored[$slug] = $this->decryptAccount($value);
                }
            }
        }

        return [
            'definition' => $run->definition,
            'currentNode' => $run->currentNode,
            'referenceDate' => $run->referenceDate,
            'stored' => $stored,
            'privateSlugs' => $run->privateSlugs,
            'ownPartyKeys' => $own,
        ];
    }

    public function declineFlowRun(string $connectionId, string $runId): mixed
    {
        return $this->http->post(self::CONN . '/' . $connectionId . '/flow-runs/' . $runId . '/decline');
    }

    /**
     * Generate the contract of a document-mode run whose LEAF this company answered — `POST
     * /api/company-connections/{id}/flow-runs/{runId}/generate`. The party that answers a run's last
     * step generates. Submitting the leaf's answers leaves the run `generating`; pass the run as
     * re-read then. The whole answer map comes from this company's OWN copy of the answers, opened
     * with the account key — every party's answers are sealed to every bound party, so that copy
     * holds the whole run and no service key is involved — and is sealed with
     * {@see Crypto::oneTimeKeyBundle()}. Returns the raw API response `[document_id, documents,
     * status]` (idempotent — a repeat answers the same document set).
     *
     * @throws ConfigError when the run's current step is not bound to this company — the participant
     *     the run lists on `$connectionId`.
     */
    public function generateFlowDocument(string $connectionId, FlowRun $run): mixed
    {
        $ownUid = null;
        foreach ($run->participants as $participant) {
            if ($participant->connectionId === $connectionId) {
                $ownUid = $participant->personUserId;
                break;
            }
        }
        // The step is checked before anything is decrypted: another party's copies do not open
        // with the account key.
        $step = $this->flowPartyView($run, false);
        $bound = false;
        foreach ($step['ownPartyKeys'] as $key) {
            if ($ownUid !== null && $ownUid !== '' && ($run->bindings[$key] ?? null) === $ownUid) {
                $bound = true;
                break;
            }
        }
        if (!$bound) {
            throw new ConfigError('run ' . $run->id . ' is not at a step this company answered');
        }

        return $this->http->post(
            self::CONN . '/' . $connectionId . '/flow-runs/' . $run->id . '/generate',
            Crypto::oneTimeKeyBundle($this->flowPartyView($run)['stored']),
        );
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
     * Drop a person's cached public key by user id. The changes feed calls this for you;
     * call it yourself when consuming `key_rotated` over a webhook (the verifier is static and has
     * no client instance).
     */
    public function invalidatePublicKey(string $userId): void
    {
        unset($this->pubkeyCache[$userId]);
    }

    /**
     * Drop a SERVICE's cached public key, so the next answer/document encrypted to it
     * refetches. The mirror of {@see invalidatePublicKey}, in the service→customer direction.
     *
     * The changes feed calls this for you on a `service_key_rotated` event; call it yourself when
     * consuming that event over a webhook, passing the body's `company_share_code` and
     * `service_share_code` (the verifier is static and has no client instance).
     *
     * No generation counter here, for the same runtime reason recorded on `$pubkeyCache` above:
     * PHP's request-scoped, single-threaded model has no window between the cache check and the
     * store in which this method could run. Under an async runtime it inherits the race and needs
     * a generation counter.
     */
    public function invalidateServiceKey(string $companyCode, string $serviceCode): void
    {
        unset($this->serviceKeyCache[$companyCode . '/' . $serviceCode]);
    }

    /** @param array<string,mixed> $event */
    private function decryptChange(array $event): Change
    {
        // A service gets no pushes, so the feed is its only rotation signal — without this
        // the cached key (including a cached `null`) would outlive the rotation for the whole
        // process lifetime.
        // The pull feed names it `event`; a raw webhook body names it `action` (and on
        // document rows `action` carries signed|accepted|cancelled instead) — so match either key.
        if (($event['event'] ?? null) === 'key_rotated' || ($event['action'] ?? null) === 'key_rotated') {
            $personId = $event['person_user_id'] ?? $event['person_id'] ?? null;
            if (is_string($personId) && $personId !== '') {
                $this->invalidatePublicKey($personId);
            }
        }
        // A service this customer connects to replaced its keypair. Same either-key match:
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
            fn (): FieldTypes => $this->fieldTypes(),
            fn (array|string $w): string => $this->decryptAccount($w),
        );
    }

    /**
     * The field-type registry — what every TYPE in a request catalog means.
     *
     * Fetched from {@code GET /api/contact-field-types} beside the connect-screen lookup this
     * client resolves a request row's type from, and held in memory for the life of the client.
     * It is what validates a typed answer before it is encrypted.
     */
    public function fieldTypes(): FieldTypes
    {
        return $this->fieldTypes ??= $this->loadFieldTypes();
    }

    /**
     * One fetch of the registry rows, with no caching of its own. A failure is raised, never
     * answered with an empty registry: "unknown accepts anything" is a verdict about the
     * deployment and must not stand in for a fetch that did not happen.
     */
    private function loadFieldTypes(): FieldTypes
    {
        // The registry route answers JSON to every caller — it is not one of the customer routes
        // that honour the configured format — so its body is parsed as JSON whatever this client
        // speaks elsewhere.
        $body = $this->http->parseBody($this->http->getResponse(self::FIELD_TYPES), false);

        return new FieldTypes(is_array($body) ? $body : []);
    }

    /**
     * One bounded refetch for a type the held registry does not carry. The refetch replaces the
     * held registry only once it has ARRIVED, so a refetch that fails leaves the rows already
     * loaded standing rather than none at all.
     */
    private function ensureTypeKnown(string $type): void
    {
        if ($this->fieldTypes()->knows($type) || isset($this->unresolvedTypes[$type])) {
            return;
        }
        $refetched = $this->loadFieldTypes();
        $this->fieldTypes = $refetched;
        if (!$refetched->knows($type)) {
            $this->unresolvedTypes[$type] = true;
        }
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
            fn (): FieldTypes => $this->fieldTypes(),
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
            fn (): FieldTypes => $this->fieldTypes(),
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
     * typed-answer validation is simply skipped.
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
        // Validate each typed answer against its request row's field type BEFORE
        // encryption. The type is resolved server-side from the connect-screen lookup
        // (cached per service); an answer whose type can't be resolved is skipped.
        $types = $this->requestFieldTypes($companyCode, $serviceCode);
        $out = [];
        foreach ($answers as $a) {
            $plain = (string) $a['value'];
            $ftype = $types[(string) $a['request_field_id']] ?? null;
            if ($ftype !== null) {
                $this->ensureTypeKnown($ftype);
                if (!$this->fieldTypes()->isFieldValueValid($ftype, $plain)) {
                    throw new ValidationError((string) $a['request_field_id'], $ftype);
                }
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
