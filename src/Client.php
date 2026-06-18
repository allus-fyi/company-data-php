<?php

declare(strict_types=1);

namespace Allus\CompanyData;

use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Errors\ConfigError;
use Allus\CompanyData\Errors\DecryptError;
use Allus\CompanyData\Errors\RateLimitError;
use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\Model\Change;
use Allus\CompanyData\Model\Connection;
use Allus\CompanyData\Model\LogEntry;
use Allus\CompanyData\Model\RequestField;
use Allus\CompanyData\Pump\Logger;
use Allus\CompanyData\Pump\NullLogger;
use Allus\CompanyData\Pump\Pump;
use Allus\CompanyData\Webhooks\Webhooks;
use phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;

/**
 * Client facade.
 *
 * The one object an integrating company touches. Build it from config (the keys
 * live there and nowhere else), then call:
 *
 *     $client->requestFields()              -> list<RequestField>  (slug -> meta, cached)
 *     $client->connections($limit, $offset) -> Generator<Connection> (auto-paged, lazy)
 *     $client->connection($id)              -> Connection
 *     $client->logs($limit, $offset)        -> list<LogEntry>
 *     $client->processChanges($handler)     -> the crash-safe pump
 *     $client->drainBatch($max)             -> list<Change>  (raw unbuffered — advanced)
 *     $client->deadLetters() / $client->retryDeadLetters($handler)
 *
 * Plus the webhook receiver helpers, exposed as methods that
 * delegate to {@see Webhooks} (all config-driven, no key/secret args):
 *
 *     $client->verifyWebhook($rawBody, $headers) -> bool
 *     $client->parseWebhook($rawBody, $headers)  -> Change
 *     $client->handleWebhook($rawBody, $headers) -> Change
 *
 * How it is wired (the "everything else the SDK hides"):
 *
 * - **Auth + transport** — an {@see HttpClient} owns the client_credentials token,
 *   the JSON/XML accept+parse, and the error mapping (incl. 429 backoff).
 * - **Decryption** — the service private key is loaded ONCE at construction from
 *   the configured encrypted PEM + passphrase into an in-memory RSA key; a
 *   decrypt closure over it is handed to every model factory and the pump
 *   (config-only key handling — the key never appears in a method signature).
 * - **Slug catalog** — requestFields() is fetched once and cached; its slug→type
 *   map types every value.
 * - **Binary** — a value's {@see \Allus\CompanyData\Crypto\BinaryHandle::bytes()}
 *   GETs the slot file endpoint, unwraps the API's
 *   {@code {"encrypted":true,"value":<wrapper>}}, and runs the same service-key
 *   decrypt → the file bytes.
 * - **Changes feed** — processChanges delegates to the {@see Pump}.
 */
final class Client
{
    private const BASE = '/api/company-data';
    private const CONNECTIONS = self::BASE . '/connections';
    private const CHANGES = self::BASE . '/changes';
    private const REQUEST_FIELDS = self::BASE . '/request-fields';
    private const LOGS = self::BASE . '/logs';

    private const DEFAULT_CONN_PAGE = 100;

    // Bounded extra backoff for the connections iterator on a surfaced 429.
    private const CONN_MAX_429_BACKOFFS = 5;
    private const CONN_DEFAULT_BACKOFF_S = 5.0;
    private const CONN_MAX_BACKOFF_S = 120.0;

    private readonly HttpClient $http;
    private readonly Logger $log;
    /** @var callable(float): void */
    private $sleep;

    private readonly RSAPrivateKey $privateKey;
    private readonly ?RSAPrivateKey $accountKey;

    /** @var list<RequestField>|null */
    private ?array $requestFields = null;
    /** @var array<string,?string> */
    private array $typeBySlug = [];

    private ?Pump $pump = null;

    /**
     * @param callable(float): void|null $sleep injectable for tests.
     *
     * @throws ConfigError when the service PEM is unreadable or the passphrase is wrong.
     */
    public function __construct(
        private readonly Config $config,
        ?HttpClient $http = null,
        ?Logger $logger = null,
        ?callable $sleep = null,
    ) {
        $this->http = $http ?? new HttpClient($config);
        $this->log = $logger ?? new NullLogger();
        $this->sleep = $sleep ?? static function (float $s): void {
            if ($s > 0) {
                usleep((int) round($s * 1_000_000));
            }
        };

        // Load the service private key ONCE from the configured encrypted PEM +
        // passphrase (config-only key handling). This is the single
        // place the key material is read; a closure over it does every decrypt.
        $this->privateKey = self::loadServiceKey($config);

        // Load the ACCOUNT key ONCE too (null unless configured). Reused for every
        // encrypt_payload webhook so we don't re-read the PEM + re-run PBKDF2 per
        // request — same one-time-load discipline as the service key.
        $this->accountKey = Webhooks::loadAccountKey($config);
    }

    // ── constructors (config-only keys) ────────────────────────────────────────

    /**
     * Build from a JSON config file (env vars override secrets).
     */
    public static function fromConfig(
        string $path,
        ?HttpClient $http = null,
        ?Logger $logger = null,
        ?callable $sleep = null,
    ): self {
        return new self(Config::fromFile($path), $http, $logger, $sleep);
    }

    /**
     * Build entirely from {@code ALLUS_*} env vars.
     */
    public static function fromEnv(
        ?HttpClient $http = null,
        ?Logger $logger = null,
        ?callable $sleep = null,
    ): self {
        return new self(Config::fromEnv(), $http, $logger, $sleep);
    }

    // ── decryption wiring (closures over the loaded key — never a method arg) ──

    /**
     * Decrypt a service-key ciphertext wrapper → plaintext (closes over the key).
     *
     * @param array<string,mixed>|string $wrapper
     */
    private function decryptValue(array|string $wrapper): string
    {
        return Crypto::decrypt($wrapper, $this->privateKey);
    }

    /**
     * Fetch a slot file endpoint and unwrap its
     * {@code {"encrypted":true,"value":...}} envelope → the inner
     * {@code {"_enc":1,...}} wrapper.
     *
     * @return array<string,mixed>|string
     */
    private function binaryFetch(string $valueUrl): array|string
    {
        $body = $this->http->get($valueUrl);
        if (is_array($body) && array_key_exists('value', $body)) {
            /** @var array<string,mixed>|string */
            return $body['value'];
        }
        // Defensive: some shapes might return the wrapper directly.
        /** @var array<string,mixed>|string $body */
        return $body;
    }

    /** Resolve a request slug to its field type (loads the catalog once). */
    private function typeForSlug(string $slug): ?string
    {
        if ($this->requestFields === null) {
            $this->requestFields();
        }
        return $this->typeBySlug[$slug] ?? null;
    }

    // ── definitions ────────────────────────────────────────────────────────────

    /**
     * The cached request-field DEFINITIONS.
     *
     * Fetched once from {@code GET /api/company-data/request-fields} and cached for
     * the life of the client. Returns YOUR request config — never the person's.
     *
     * @return list<RequestField>
     */
    public function requestFields(): array
    {
        if ($this->requestFields === null) {
            $body = $this->http->get(self::REQUEST_FIELDS);
            $fields = RequestField::listFromApi(is_array($body) ? $body : []);
            $this->requestFields = $fields;
            $map = [];
            foreach ($fields as $f) {
                if ($f->slug !== null) {
                    $map[$f->slug] = $f->type;
                }
            }
            $this->typeBySlug = $map;
        }
        return $this->requestFields;
    }

    // ── connections (heavily rate-limited — initial sync / reconciliation) ─────

    /**
     * A lazy generator paging the list endpoint, yielding one Connection at a time.
     *
     * {@code $limit} is the page size; {@code $offset} the starting offset. The
     * generator auto-pages {@code GET /api/company-data/connections?limit&offset}
     * and yields typed {@see Connection} objects (each {@code values[slug]} already
     * decrypted / a lazy binary handle) one at a time — bounded memory for a large
     * book.
     *
     * The connections endpoints are **heavily rate-limited**: use
     * this for the initial full sync + occasional reconciliation, never as a poll
     * substitute for the changes feed. On a surfaced {@see RateLimitError} the
     * generator backs off per {@code Retry-After} and retries the page a bounded
     * number of times before re-raising.
     *
     * @return \Generator<int, Connection>
     */
    public function connections(int $limit = self::DEFAULT_CONN_PAGE, int $offset = 0): \Generator
    {
        $page = max(1, $limit);
        $cur = max(0, $offset);
        // Ensure the slug catalog is loaded so values are typed correctly.
        $this->requestFields();

        while (true) {
            $body = $this->getConnectionsPage($page, $cur);
            $items = self::listItems($body);
            if ($items === []) {
                return;
            }
            foreach ($items as $obj) {
                if (!is_array($obj)) {
                    continue;
                }
                yield Connection::fromApi(
                    $obj,
                    fn (string $slug): ?string => $this->typeForSlug($slug),
                    fn (array|string $w): string => $this->decryptValue($w),
                    fn (string $u): array|string => $this->binaryFetch($u),
                    // The list row carries identity AND the values map.
                    identity: $obj,
                );
            }
            // A short page means we reached the end.
            if (count($items) < $page) {
                return;
            }
            $cur += $page;
        }
    }

    /**
     * GET one connections page, backing off on a surfaced 429.
     *
     * @return array<string,mixed>|list<mixed>|string
     */
    private function getConnectionsPage(int $page, int $offset): array|string
    {
        $attempts = 0;
        while (true) {
            try {
                return $this->http->get(self::CONNECTIONS, ['limit' => $page, 'offset' => $offset]);
            } catch (RateLimitError $exc) {
                $attempts++;
                if ($attempts > self::CONN_MAX_429_BACKOFFS) {
                    throw $exc;
                }
                $delay = self::connBackoff($exc->retryAfter, $attempts);
                $this->log->warning(sprintf(
                    'connections rate-limited (offset=%d); backoff %.1fs (attempt %d)',
                    $offset,
                    $delay,
                    $attempts,
                ));
                if ($delay > 0) {
                    ($this->sleep)($delay);
                }
            }
        }
    }

    /**
     * Fetch a single connection by id → one {@see Connection}.
     *
     * {@code GET /api/company-data/connections/{id}} returns
     * {@code {connection_id, user_id, values}} and no display_name/connected_at;
     * those identity fields stay null (the list endpoint carries them).
     */
    public function connection(string $id): Connection
    {
        $this->requestFields();
        $body = $this->http->get(self::CONNECTIONS . '/' . rawurlencode($id));
        if (is_array($body) && array_key_exists('items', $body) && !array_key_exists('values', $body)) {
            // Defensive: a single-item list shape.
            $items = self::listItems($body);
            $body = $items[0] ?? [];
        }
        /** @var array<string,mixed> $obj */
        $obj = is_array($body) ? $body : [];
        return Connection::fromApi(
            $obj,
            fn (string $slug): ?string => $this->typeForSlug($slug),
            fn (array|string $w): string => $this->decryptValue($w),
            fn (string $u): array|string => $this->binaryFetch($u),
        );
    }

    // ── logs (moderate rate-limit) ──────────────────────────────────────────────

    /**
     * The service's activity log → {@code list<LogEntry>}.
     *
     * {@code GET /api/company-data/logs?limit&offset}. Ops events only (email /
     * purge / webhook) — never person field data.
     *
     * @return list<LogEntry>
     */
    public function logs(int $limit = 50, int $offset = 0): array
    {
        $body = $this->http->get(self::LOGS, ['limit' => max(1, $limit), 'offset' => max(0, $offset)]);
        return LogEntry::listFromApi(is_array($body) ? $body : []);
    }

    // ── changes feed — the crash-safe pump ────────────────────────

    /** The crash-safe changes {@see Pump} (built lazily). */
    public function pump(): Pump
    {
        if ($this->pump === null) {
            $this->pump = new Pump(
                $this->config,
                fn (int $limit): array => $this->fetchChanges($limit),
                fn (array $event): Change => $this->decryptChange($event),
                $this->log,
                $this->sleep,
            );
        }
        return $this->pump;
    }

    /**
     * The pump's drain source: {@code GET /changes?limit=} → raw ciphertext events.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchChanges(int $limit): array
    {
        $body = $this->http->get(self::CHANGES, ['limit' => $limit]);
        if (is_array($body) && array_key_exists('changes', $body)) {
            $items = $body['changes'];
        } else {
            $items = is_array($body) && array_is_list($body) ? $body : [];
        }
        $out = [];
        foreach ($items as $o) {
            if (is_array($o)) {
                $out[] = $o;
            }
        }
        return $out;
    }

    /**
     * The pump's decrypt: a raw event dict → a typed {@see Change} (value at delivery).
     *
     * @param array<string,mixed> $event
     */
    private function decryptChange(array $event): Change
    {
        return Change::fromApi(
            $event,
            fn (string $slug): ?string => $this->typeForSlug($slug),
            fn (array|string $w): string => $this->decryptValue($w),
            fn (string $u): array|string => $this->binaryFetch($u),
        );
    }

    /**
     * Drain the changes feed through {@code $handler} one at a time, crash-safely.
     *
     * Delegates to the {@see Pump}. {@code $handler} must be idempotent
     * (at-least-once; dedup on {@code Change->id}). Options:
     * {@code batchSize} (≤500), {@code maxRetries}, {@code onError}
     * ({@code deadletter}|{@code halt}), {@code backoff}.
     *
     * @param callable(Change): void $handler
     * @param callable(int): float|null $backoff
     */
    public function processChanges(
        callable $handler,
        int $batchSize = 100,
        int $maxRetries = 3,
        string $onError = 'deadletter',
        ?callable $backoff = null,
    ): void {
        $this->requestFields(); // ensure the catalog is loaded for value typing
        $this->pump()->processChanges($handler, $batchSize, $maxRetries, $onError, $backoff);
    }

    /**
     * Raw, UNBUFFERED drain → {@code list<Change>} (advanced — you own durability).
     *
     * @return list<Change>
     */
    public function drainBatch(int $max = self::DEFAULT_CONN_PAGE): array
    {
        $this->requestFields();
        return $this->pump()->drainBatch($max);
    }

    /**
     * The local dead-letter store.
     *
     * @return list<array<string,mixed>>
     */
    public function deadLetters(): array
    {
        return $this->pump()->deadLetters();
    }

    /**
     * Re-drive dead-lettered events through {@code $handler}.
     *
     * @param callable(Change): void $handler
     * @param callable(int): float|null $backoff
     */
    public function retryDeadLetters(
        callable $handler,
        int $maxRetries = 3,
        string $onError = 'deadletter',
        ?callable $backoff = null,
    ): int {
        $this->requestFields();
        return $this->pump()->retryDeadLetters($handler, $maxRetries, $onError, $backoff);
    }

    // ── webhook receiver helpers ─────

    /**
     * Verify a webhook's {@code X-Allus-Signature} HMAC.
     *
     * @param array<string,string> $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return Webhooks::verify($rawBody, $headers, $this->config);
    }

    /**
     * Parse a webhook body → a typed {@see Change}.
     *
     * @param array<string,string> $headers
     */
    public function parseWebhook(string $rawBody, array $headers): Change
    {
        return Webhooks::parse(
            $rawBody,
            $headers,
            $this->config,
            fn (string $slug): ?string => $this->typeForSlug($slug),
            fn (array|string $w): string => $this->decryptValue($w),
            fn (string $u): array|string => $this->binaryFetch($u),
            $this->accountKey, // cached once; no per-webhook PBKDF2
        );
    }

    /**
     * Verify + parse a webhook in one call → {@see Change}.
     *
     * @param array<string,string> $headers
     */
    public function handleWebhook(string $rawBody, array $headers): Change
    {
        return Webhooks::handle(
            $rawBody,
            $headers,
            $this->config,
            fn (string $slug): ?string => $this->typeForSlug($slug),
            fn (array|string $w): string => $this->decryptValue($w),
            fn (string $u): array|string => $this->binaryFetch($u),
            $this->accountKey, // cached once; no per-webhook PBKDF2
        );
    }

    // ── module-level helpers ──────────────────────────────────────────────────

    /**
     * Read the configured encrypted PEM and decrypt it with the passphrase (once).
     *
     * @throws ConfigError on a read / passphrase / PEM problem (fail fast).
     */
    private static function loadServiceKey(Config $config): RSAPrivateKey
    {
        $pem = @file_get_contents($config->servicePrivateKey);
        if ($pem === false) {
            throw new ConfigError("could not read service_private_key PEM: {$config->servicePrivateKey}");
        }
        try {
            return Crypto::loadPrivateKey($pem, $config->keyPassphrase);
        } catch (DecryptError $e) {
            // A bad passphrase / malformed PEM is a configuration problem (fail fast).
            throw new ConfigError("could not load service private key: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Pull the {@code items} array out of a {@code {total, items}} list response.
     *
     * @param array<string,mixed>|list<mixed>|string $body
     *
     * @return list<mixed>
     */
    private static function listItems(array|string $body): array
    {
        if (is_array($body)) {
            if (array_is_list($body)) {
                return $body;
            }
            $items = $body['items'] ?? null;
            return is_array($items) ? array_values($items) : [];
        }
        return [];
    }

    /** Backoff before retrying a rate-limited connections page. */
    private static function connBackoff(?float $retryAfter, int $attempt): float
    {
        if ($retryAfter !== null && $retryAfter >= 0) {
            return min($retryAfter, self::CONN_MAX_BACKOFF_S);
        }
        return min(self::CONN_DEFAULT_BACKOFF_S * (2 ** ($attempt - 1)), self::CONN_MAX_BACKOFF_S);
    }
}
