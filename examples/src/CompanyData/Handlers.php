<?php

declare(strict_types=1);

namespace Allus\Examples\CompanyData;

use Allus\CompanyData\Client;
use Allus\CompanyData\Crypto\BinaryHandle;
use Allus\CompanyData\Errors\WebhookError;
use Allus\CompanyData\Model\Change;
use Allus\Examples\Family;
use Allus\Examples\Response;
use Allus\Examples\Runtime;

/**
 * The COMPANY-DATA scenario handlers (spec §2/§3, config-file amendment). Each handler runs the intended
 * top-level SDK surface ONLY (no raw platform HTTP, no SDK internals).
 *
 * Five scenarios, all namespaced companydata:* (spec §3):
 *   read        — Client::connections()        → connection-grouped decrypted values
 *   definitions — Client::requestFields()       → your request-field catalog
 *   changes     — Client::processChanges()      → a crash-safe pump drain (idempotent on Change.id)
 *   webhook     — verifyWebhook()+parseWebhook() → a public POST /webhook receiver + a drainBatch() feed
 *                                                  fallback; ONE accumulating run keyed by the webhook id
 *   documents   — Client::createDocument()        → the document/contract types selected in setup
 *                                                    (six offered, all ticked by default)
 *
 * Settings flow (amendment): the browser POSTs a scenario's setup values to /config, which writes them to
 * a canonical SDK config FILE (.runtime/config/{sid}.json). start() builds the Client from that file
 * (Client::fromConfig → Config::fromFile) and runs OFF it. A /start with no saved config → 409.
 */
final class Handlers implements Family
{
    public const FAMILY = 'companydata';

    /** id => "runnable". Every company-data scenario runs synchronously (data) or accumulates (webhook). */
    private const SCENARIOS = [
        'companydata:read' => 'runnable',
        'companydata:definitions' => 'runnable',
        'companydata:changes' => 'runnable',
        'companydata:webhook' => 'runnable',
        'companydata:documents' => 'runnable',
    ];

    private const READ = 'companydata:read';
    private const DEFINITIONS = 'companydata:definitions';
    private const CHANGES = 'companydata:changes';
    private const WEBHOOK = 'companydata:webhook';
    private const DOCUMENTS = 'companydata:documents';

    /** Scenarios whose SDK Client uses the pump (needs a cache_dir for its buffer/dead-letters). */
    private const PUMP_SCENARIOS = [self::CHANGES, self::WEBHOOK];

    private const DEFAULT_API_URL = 'https://api.allme.fyi';

    /**
     * The "what just happened" trace. Every entry is `<SDK method> — <what that call did in THIS
     * scenario>`, appended AT the call site, in the order the calls were made; an entry wrapped in
     * parentheses is a step that is deliberately NOT an SDK call. Keep them in step when this handler
     * changes.
     */
    private const CALL_SERVICE_BUILD = 'Client::fromConfig — builds the SERVICE-role data client from the saved config file: client credentials plus the service private key, decrypted with its passphrase';
    private const CALL_CONNECTIONS = 'Client::connections — pages GET /api/company-data/connections: loads your request-field catalog first for value typing, then decrypts each person\'s values with the service key';
    private const CALL_REQUEST_FIELDS = 'Client::requestFields — GET /api/company-data/request-fields: your own request-field catalog, fetched once and cached for the life of the client';
    private const CALL_PROCESS_CHANGES = 'Client::processChanges — drains the change feed through the crash-safe pump: handler before ack, at-least-once (dedup on Change.id), failures to the local dead-letter store';
    private const CALL_CREATE_DOCUMENT = 'Client::createDocument — %s';
    private const CALL_WEBHOOK_STARTED = '(webhook run started) — POST /webhook receives each delivery; every poll also drains the change feed as a fallback';
    private const CALL_VERIFY_WEBHOOK = 'Client::verifyWebhook — checks the delivery\'s X-Allus-Signature HMAC against the secret configured for its X-Allus-Webhook-Id; a failure answers 401';
    private const CALL_PARSE_WEBHOOK = 'Client::parseWebhook — turns the verified body into a typed Change, decrypting its value with the service key';
    private const CALL_DRAIN_BATCH = 'Client::drainBatch — the per-poll feed fallback: one unbuffered drain, so events still show up when no delivery can reach this machine';

    public function __construct(private readonly Runtime $rt)
    {
    }

    /** @return list<array{id:string,kind:string}> */
    public function scenarios(): array
    {
        $out = [];
        foreach (self::SCENARIOS as $id => $kind) {
            $out[] = ['id' => $id, 'kind' => $kind];
        }
        return $out;
    }

    // ── POST /api/scenarios/{id}/config (amendment) ────────────────────────────

    /**
     * Write the browser's setup values to a canonical SDK config FILE (spec §3). Every company-data
     * scenario uses the SERVICE-role Client, so the config always carries client_id/secret + the service
     * PEM (by path) + passphrase. The webhook scenario adds the webhooks:{id:secret} map (the SDK selects
     * the secret by the X-Allus-Webhook-Id header) and records the webhook id in a meta sidecar (the
     * routing key /start needs). The documents scenario records the target share code in the sidecar.
     *
     * @param array<string,mixed> $in
     */
    public function config(string $id, array $in): Response
    {
        if (!isset(self::SCENARIOS[$id])) {
            return Response::json(['error' => 'not_found'], 404);
        }

        // Canonical SDK config — the service role for every company-data scenario (sdk.html §2).
        $cfg = [
            'api_url' => rtrim((string) ($in['apiUrl'] ?? '') ?: self::DEFAULT_API_URL, '/'),
            'client_id' => (string) ($in['clientId'] ?? ''),
            'client_secret' => (string) ($in['clientSecret'] ?? ''),
            'key_passphrase' => (string) ($in['keyPassphrase'] ?? ''),
        ];
        $pem = (string) ($in['servicePrivateKeyPem'] ?? '');
        if ($pem !== '') {
            $cfg['service_private_key'] = $this->rt->materializeConfigKey($pem);
        }

        // Pump scenarios persist their buffer/dead-letters under .runtime/cache (Config.cacheDir).
        if (in_array($id, self::PUMP_SCENARIOS, true)) {
            $cfg['cache_dir'] = $this->rt->cacheDir;
        }

        $meta = [];
        if ($id === self::WEBHOOK) {
            // The verifier selects the secret by the delivery's X-Allus-Webhook-Id header, so the config's
            // webhooks map must be keyed by the real webhook id (spec §2).
            $webhookId = (string) ($in['webhookId'] ?? '');
            $secret = (string) ($in['webhookSecret'] ?? '');
            if ($webhookId !== '' && $secret !== '') {
                $cfg['webhooks'] = [$webhookId => $secret];
            }
            if ($webhookId !== '') {
                $meta['webhook_id'] = $webhookId; // the routing key /start writes into the route record
            }
        }
        if ($id === self::DOCUMENTS) {
            $meta['share_code'] = (string) ($in['shareCode'] ?? ''); // the per-person/contract target
            // Preserve presence so doDocuments() can distinguish an explicit empty selection from
            // an absent selection; absence means all document types.
            if (array_key_exists('documentTypes', $in)) {
                $meta['document_types'] = array_values(array_map('strval', (array) $in['documentTypes']));
            }
        }

        $configPath = $this->rt->writeConfig($id, $cfg);
        $this->rt->writeConfigMeta($id, $meta);

        return Response::json(['ok' => true, 'configPath' => $configPath]);
    }

    // ── POST /api/scenarios/{id}/start ─────────────────────────────────────────

    public function start(string $id): Response
    {
        if (!isset(self::SCENARIOS[$id])) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if (!$this->rt->hasConfig($id)) {
            // The run is built from the persisted config file, not the request body (amendment).
            return Response::json(['error' => 'not_configured'], 409);
        }

        switch ($id) {
            case self::READ:
                return $this->dataRun($id, fn (Client $c, array &$calls): array => $this->doRead($c, $calls));
            case self::DEFINITIONS:
                return $this->dataRun($id, fn (Client $c, array &$calls): array => $this->doDefinitions($c, $calls));
            case self::CHANGES:
                return $this->dataRun($id, fn (Client $c, array &$calls): array => $this->doChanges($c, $calls));
            case self::DOCUMENTS:
                return $this->dataRun($id, fn (Client $c, array &$calls): array => $this->doDocuments($c, $calls));
            case self::WEBHOOK:
                return $this->startWebhook();
        }

        return Response::json(['error' => 'not_found'], 404);
    }

    /**
     * Run a synchronous data scenario: build the Client from the config file, run the SDK call, and store
     * the terminal result. The immediate outcome is read once via GET /api/runs (action {type:"data"}).
     *
     * @param callable(Client, array<int,string>): array<string,mixed> $do
     */
    private function dataRun(string $id, callable $do): Response
    {
        $runId = $this->rt->newRunId();
        $calls = [];
        try {
            $calls[] = self::CALL_SERVICE_BUILD;
            $client = Client::fromConfig($this->rt->configPathFor($id));
            $result = $do($client, $calls);
            $this->rt->writeRun($runId, ['family' => self::FAMILY, 'scenario' => $id, 'status' => 'done', 'result' => $result, 'calls' => $calls]);
        } catch (\Throwable $e) {
            $this->rt->writeRun($runId, ['family' => self::FAMILY, 'scenario' => $id, 'status' => 'failed', 'error' => $e->getMessage(), 'calls' => $calls]);
        }
        return Response::json(['runId' => $runId, 'action' => ['type' => 'data']]);
    }

    /**
     * companydata:read — Client::connections() grouped BY connection (one card per connected person), so
     * two people who both filled the same slug stay distinguishable (spec §2/§3).
     *
     * @param array<int,string> $calls
     * @return array<string,mixed>
     */
    private function doRead(Client $client, array &$calls): array
    {
        $calls[] = self::CALL_CONNECTIONS;
        $connections = [];
        foreach ($client->connections() as $conn) {
            $values = [];
            foreach ($conn->values as $slug => $v) {
                $values[] = [
                    'slug' => (string) $slug,
                    'value' => $this->stringifyValue($v->value),
                    'live' => $v->live,
                    'at' => $v->updatedAt?->format(DATE_ATOM),
                ];
            }
            $connections[] = [
                'connectionId' => $conn->id,
                'personId' => $conn->personId,
                'displayName' => $conn->displayName,
                'customerType' => $conn->customerType,
                'shareCode' => $conn->shareCode,
                'values' => $values,
            ];
        }
        return ['connections' => $connections];
    }

    /**
     * companydata:definitions — Client::requestFields() → your request-field catalog (the folded
     * mandatory bool + one_time; the raw split flags are debug-only, off the intended surface).
     *
     * @param array<int,string> $calls
     * @return array<string,mixed>
     */
    private function doDefinitions(Client $client, array &$calls): array
    {
        $calls[] = self::CALL_REQUEST_FIELDS;
        $fields = [];
        foreach ($client->requestFields() as $f) {
            $fields[] = [
                'slug' => $f->slug,
                'label' => $f->label,
                'type' => $f->type,
                'mandatory' => $f->mandatory,
                'one_time' => $f->oneTime,
            ];
        }
        return ['fields' => $fields];
    }

    /**
     * companydata:changes — Client::processChanges() drains the feed on start through the crash-safe pump
     * (handler-before-ack, at-least-once), so the append handler is idempotent on the pull-feed Change.id
     * (spec §2, sdk.html §6.1). Each event is the rendered-column projection PLUS a raw object with the
     * full public Change fields, so a raw view of the event can still show its extras.
     *
     * @param array<int,string> $calls
     * @return array<string,mixed>
     */
    private function doChanges(Client $client, array &$calls): array
    {
        $calls[] = self::CALL_PROCESS_CHANGES;
        $events = [];
        $seen = [];
        $client->processChanges(function (Change $c) use (&$events, &$seen): void {
            $id = $c->id;
            if ($id !== null) {
                if (isset($seen[$id])) {
                    return; // idempotent: the pump may replay after a crash — dedup on Change.id
                }
                $seen[$id] = true;
            }
            $events[] = $this->projectChange($c, null);
        });
        return ['events' => $events, 'drained' => true];
    }

    /**
     * companydata:documents — Client::createDocument() for each SELECTED document/contract type, of
     * the six the scenario offers (payloads verbatim from apitests/php/documents.php). The
     * per-person / private / contract types target the connected person by share code (from the
     * setup sidecar). Selection comes from the sidecar's document_types list; absence means all six.
     *
     * @param array<int,string> $calls
     * @return array<string,mixed>
     */
    private function doDocuments(Client $client, array &$calls): array
    {
        $meta = $this->rt->readConfigMeta(self::DOCUMENTS);
        $shareCode = (string) ($meta['share_code'] ?? '');
        $hasTypes = array_key_exists('document_types', $meta);
        $selectedTypes = array_map('strval', (array) ($meta['document_types'] ?? []));
        $pdf = self::minimalPdf(...);
        $specs = [
            ['key' => 'broadcast_json', 'label' => 'Broadcast plaintext JSON (no target)', 'perPerson' => false, 'opts' => [
                'name' => 'Service notice', 'payload_kind' => 'json',
                'json_value' => ['msg' => 'Scheduled maintenance Sunday'],
            ]],
            ['key' => 'broadcast_pdf', 'label' => 'Broadcast PDF file (no target)', 'perPerson' => false, 'opts' => [
                'name' => 'Price list', 'payload_kind' => 'file',
                'file_bytes' => $pdf('Price list'), 'file_mime' => 'application/pdf',
            ]],
            ['key' => 'per_person_file', 'label' => 'Per-person NON-private file', 'perPerson' => true, 'opts' => [
                'name' => 'Your invoice', 'payload_kind' => 'file',
                'file_bytes' => $pdf('Your invoice'), 'file_mime' => 'application/pdf',
            ]],
            ['key' => 'per_person_private', 'label' => 'Per-person PRIVATE file (lock → reveal)', 'perPerson' => true, 'opts' => [
                'name' => 'Confidential report', 'payload_kind' => 'file', 'is_private' => true,
                'file_bytes' => $pdf('Confidential report'), 'file_mime' => 'application/pdf',
            ]],
            ['key' => 'contract_signature', 'label' => 'CONTRACT requiring SIGNATURE', 'perPerson' => true, 'opts' => [
                'name' => 'Service agreement', 'kind' => 'agreement', 'payload_kind' => 'file',
                'requires_signature' => true,
                'file_bytes' => $pdf('Service agreement'), 'file_mime' => 'application/pdf',
                'metadata' => ['can_be_cancelled_in_app' => true],
            ]],
            ['key' => 'contract_acceptance', 'label' => 'CONTRACT requiring ACCEPTANCE', 'perPerson' => true, 'opts' => [
                'name' => 'Terms update', 'kind' => 'agreement', 'payload_kind' => 'json',
                'requires_acceptance' => true, 'json_value' => ['version' => '2.0'],
                'metadata' => [
                    'plan_name' => 'Pro Plan',
                    'price' => '9.99',
                    'currency' => 'EUR',
                    'renewal_term' => 'Monthly',
                    'renewal_date' => '2026-07-30',
                    'valid_until' => '2027-06-30',
                    'can_be_cancelled_in_app' => true,
                    'management_url' => 'https://example.com/manage',
                ],
            ]],
        ];

        $docs = [];
        foreach ($specs as $spec) {
            if ($hasTypes && !in_array($spec['key'], $selectedTypes, true)) {
                continue; // deselected in setup — the scenario runs exactly what was chosen
            }
            $opts = $spec['opts'];
            if ($spec['perPerson']) {
                if ($shareCode === '') {
                    throw new \RuntimeException(
                        'this document type targets a connected person — set a target person share code in the setup, then re-run'
                    );
                }
                $opts['share_code'] = $shareCode;
            }
            $calls[] = sprintf(self::CALL_CREATE_DOCUMENT, $spec['label']);
            $doc = $client->createDocument($opts);
            $docs[] = [
                'index' => count($docs) + 1,
                'label' => $spec['label'],
                'document_id' => $doc->id,
                'status' => $doc->status,
            ];
        }
        return ['docs' => $docs];
    }

    // ── companydata:webhook — the accumulating run + public receiver ────────────

    /**
     * Start the single accumulating webhook run (spec §2/§3). Persists the routing record
     * webhookId → runId (superseding any prior active webhook run) and returns {action:{type:"none"}} —
     * there is NO long-poll (it would wedge the single worker). Events arrive via POST /webhook and via a
     * per-poll drainBatch() feed fallback; the growing list is exposed via GET /api/runs for polling.
     */
    private function startWebhook(): Response
    {
        $webhookId = (string) ($this->rt->readConfigMeta(self::WEBHOOK)['webhook_id'] ?? '');
        if ($webhookId === '') {
            return Response::json(['error' => 'not_configured'], 409);
        }
        $runId = $this->rt->newRunId();
        $this->rt->writeRun($runId, [
            'family' => self::FAMILY,
            'scenario' => self::WEBHOOK,
            'status' => 'pending', // accumulating — the v1 enum is unchanged (spec §3)
            'webhookId' => $webhookId,
            'events' => [],
            'seenFeedIds' => [], // feed-only dedup set for the drainBatch() fallback
            'unparseable' => 0,
            'calls' => [self::CALL_WEBHOOK_STARTED],
        ]);
        $this->rt->writeRoute($webhookId, $runId);
        return Response::json(['runId' => $runId, 'action' => ['type' => 'none']]);
    }

    /**
     * POST /webhook — the PUBLIC inbound delivery (spec §2). The exact call/status sequence (never the
     * combined handleWebhook(), which throws one WebhookError for BOTH bad-HMAC and a parse failure):
     *   (1) read X-Allus-Webhook-Id; unknown/stale id or no active run → 200 acknowledge-and-discard.
     *   (2) verifyWebhook(): false → 401 (a genuine signature failure; misconfiguration should be loud).
     *   (3) parseWebhook(): success → append (source:"webhook") + 200; a WebhookError here is a
     *       VERIFIED-but-unparseable delivery → 200 acknowledge-and-note (increment unparseable) — NOT
     *       401, since the signature was valid.
     * All accepted-and-dropped cases return 200 because the platform worker counts EXACTLY 200 as success
     * (202/401/other = failure → retry + circuit-break).
     *
     * @param array<string,string> $headers
     */
    public function webhook(string $rawBody, array $headers): Response
    {
        $webhookId = $this->header($headers, 'X-Allus-Webhook-Id');

        $route = $this->rt->readRoute();
        if ($route === null || $webhookId === null || $webhookId !== $route['webhookId']) {
            return Response::text('discarded: unknown or stale webhook id', 200);
        }
        $run = $this->rt->readRun($route['runId']);
        if ($run === null) {
            return Response::text('discarded: no active webhook run', 200);
        }

        $this->recordCall($run, self::CALL_SERVICE_BUILD);
        $client = Client::fromConfig($this->rt->configPathFor(self::WEBHOOK));
        $this->recordCall($run, self::CALL_VERIFY_WEBHOOK);
        if (!$client->verifyWebhook($rawBody, $headers)) {
            // A genuine signature failure — persist the attempted verify so the calls trace is truthful
            // even on the reject path (spec §4).
            $this->rt->writeRun($route['runId'], $run);
            return Response::text('signature verification failed', 401);
        }
        try {
            $this->recordCall($run, self::CALL_PARSE_WEBHOOK);
            $change = $client->parseWebhook($rawBody, $headers);
            $run['events'][] = $this->projectChange($change, 'webhook');
        } catch (WebhookError $e) {
            // Verified but unparseable/undecryptable — acknowledge (200) and note it in the raw view.
            $run['unparseable'] = (int) ($run['unparseable'] ?? 0) + 1;
            $run['events'][] = [
                'source' => 'webhook',
                'event' => null,
                'id' => null,
                'note' => 'received, could not parse',
                'raw' => ['error' => $e->getMessage()],
            ];
        }
        $this->rt->writeRun($route['runId'], $run);
        return Response::text('ok', 200);
    }

    // ── GET /api/runs/{runId} ──────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $run
     */
    public function run(string $runId, array $run): Response
    {
        // The accumulating webhook run: each poll also does ONE immediate drainBatch() raw feed fetch
        // (NOT processChanges(), which loops the pump to empty and could stall the single worker) so
        // events generated AFTER start still appear in deployed-no-tunnel mode (spec §2/§3).
        if (($run['scenario'] ?? '') === self::WEBHOOK) {
            $run = $this->webhookFeedFallback($runId, $run);
            return Response::json([
                'status' => $run['status'] ?? 'pending',
                'calls' => $run['calls'] ?? [],
                'result' => [
                    'webhookId' => $run['webhookId'] ?? '',
                    'events' => $run['events'] ?? [],
                    'unparseable' => (int) ($run['unparseable'] ?? 0),
                ],
            ]);
        }

        $out = ['status' => $run['status'] ?? 'pending', 'calls' => $run['calls'] ?? []];
        if (isset($run['result'])) {
            $out['result'] = $run['result'];
        }
        if (isset($run['error'])) {
            $out['error'] = $run['error'];
        }
        return Response::json($out);
    }

    /**
     * One immediate drainBatch() fetch per poll for the active webhook run, appending new source:"feed"
     * events deduped on the pull-feed Change.id (a feed-only seen-id set in run state). Only the CURRENT
     * active run pulls (a superseded run stops receiving). A transport/API error is swallowed so a
     * blackholed feed never fails the accumulating run — the webhook path still works.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function webhookFeedFallback(string $runId, array $run): array
    {
        $route = $this->rt->readRoute();
        if ($route === null || $route['runId'] !== $runId) {
            return $run; // superseded/cleared — this run no longer pulls
        }
        $seen = [];
        foreach (($run['seenFeedIds'] ?? []) as $sid) {
            $seen[(string) $sid] = true;
        }
        try {
            $buildNew = $this->recordCall($run, self::CALL_SERVICE_BUILD);
            $client = Client::fromConfig($this->rt->configPathFor(self::WEBHOOK));
            // Every poll ATTEMPTS the feed pull — record the call now (deduped), so an empty poll still
            // reports the drainBatch it performed rather than claiming no call (spec §4).
            $drainNew = $this->recordCall($run, self::CALL_DRAIN_BATCH);
            $appended = false;
            foreach ($client->drainBatch() as $change) {
                $cid = $change->id;
                if ($cid !== null) {
                    if (isset($seen[$cid])) {
                        continue;
                    }
                    $seen[$cid] = true;
                    $run['seenFeedIds'][] = $cid;
                }
                $run['events'][] = $this->projectChange($change, 'feed');
                $appended = true;
            }
            if ($appended || $drainNew || $buildNew) {
                $this->rt->writeRun($runId, $run);
            }
        } catch (\Throwable) {
            // A blackholed/failed feed fetch must not fail the accumulating webhook run.
        }
        return $run;
    }

    /**
     * Append an SDK-call name to a run's "what just happened" trace, deduped so the panel stays small and
     * readable no matter how many deliveries/polls a call is attempted across. Returns true when the name
     * was newly added (so the caller can persist on that transition). Record a call when it is ATTEMPTED —
     * the trace is a teaching outcome and must be truthful on every path (spec §4).
     *
     * @param array<string,mixed> $run
     */
    private function recordCall(array &$run, string $name): bool
    {
        return Runtime::recordCall($run, $name); // ONE implementation for all three families (standards §1)
    }

    // ── Change projection ──────────────────────────────────────────────────────

    /**
     * The rendered-column projection of a Change PLUS a raw object holding the full public Change fields
     * so a raw view of the event can still show its event-specific extras — the compact renderer uses
     * only the leading columns and ignores raw (spec §2.3). Nothing is dropped from result. $source
     * labels a webhook delivery vs a pull-feed row (null for the changes scenario, where every row is a
     * pull-feed drain).
     *
     * @return array<string,mixed>
     */
    private function projectChange(Change $c, ?string $source): array
    {
        $event = [
            'event' => $c->event,
            'personId' => $c->personId,
            'shareCode' => $c->shareCode,
            'customerType' => $c->customerType,
            'slug' => $c->slug,
            'value' => $this->stringifyValue($c->value),
            'live' => $c->live,
            'at' => $c->at?->format(DATE_ATOM),
            'documentId' => $c->documentId,
            'status' => $c->status,
            'action' => $c->action,
            'id' => $c->id,
            'raw' => [
                'id' => $c->id,
                'event' => $c->event,
                'personId' => $c->personId,
                'shareCode' => $c->shareCode,
                'customerType' => $c->customerType,
                'slug' => $c->slug,
                'value' => $this->stringifyValue($c->value),
                'live' => $c->live,
                'documentId' => $c->documentId,
                'status' => $c->status,
                'action' => $c->action,
                'note' => $c->note,
                'method' => $c->method,
                'contentSha256' => $c->contentSha256,
                'signedAt' => $c->signedAt,
                'cancelEffectiveDate' => $c->cancelEffectiveDate,
                'requestId' => $c->requestId,
                'publicKeySha256' => $c->publicKeySha256,
                'verified' => $c->verified,
                'at' => $c->at?->format(DATE_ATOM),
            ],
        ];
        if ($source !== null) {
            $event = ['source' => $source] + $event;
        }
        return $event;
    }

    /**
     * Render a decrypted value for JSON. A binary value is a lazy {@see BinaryHandle} — resolve its bytes
     * to a short descriptor (spec §6 scenario 4: a binary document event resolves its value_url) rather
     * than dumping raw bytes; a structured value stays an array so it remains valid JSON as-is.
     */
    private function stringifyValue(mixed $v): mixed
    {
        if ($v === null || is_bool($v) || is_int($v) || is_float($v) || is_string($v) || is_array($v)) {
            return $v;
        }
        if ($v instanceof \DateTimeImmutable) {
            return $v->format(DATE_ATOM);
        }
        if ($v instanceof BinaryHandle) {
            try {
                return '[binary ' . strlen($v->bytes()) . ' bytes]';
            } catch (\Throwable) {
                return '[binary value]';
            }
        }
        return (string) $v;
    }

    // ── input helpers ────────────────────────────────────────────────────────

    /**
     * Case-insensitive header lookup.
     *
     * @param array<string,string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        $target = strtolower($name);
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $target) {
                return (string) $v;
            }
        }
        return null;
    }

    /**
     * A tiny valid one-page PDF carrying $label (verbatim shape from apitests/php/documents.php) — so the
     * broadcast/per-person/contract file docs upload real bytes without a fixture file.
     */
    private static function minimalPdf(string $label): string
    {
        $stream = "BT /F1 18 Tf 40 90 Td (" . str_replace(['(', ')'], ['[', ']'], $label) . ") Tj ET";
        $objs = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 420 160] '
                . '/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $bodyObj) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "$n 0 obj\n$bodyObj\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($objs as $n => $_) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";
        return $pdf;
    }
}
