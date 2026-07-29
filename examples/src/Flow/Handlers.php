<?php

declare(strict_types=1);

namespace Allus\Examples\Flow;

use Allus\CompanyData\Client;
use Allus\CompanyData\Errors\ApiError;
use Allus\CompanyData\Errors\ConfigError;
use Allus\CompanyData\Errors\ValidationError;
use Allus\CompanyData\Model\Connection;
use Allus\CompanyData\Model\FlowRun;
use Allus\Examples\Family;
use Allus\Examples\Response;
use Allus\Examples\Runtime;

/**
 * The FLOW scenario handler — the ONE contract-flow scenario (spec §2/§4, config-file amendment). Each
 * handler runs the intended top-level SDK flow surface only (identity / triggerFlowRun / flowRun /
 * processFlowRun / flowRunAnswers / flowRunDocument). Handlers NEVER perform raw platform HTTP.
 *
 * Single scenario "flow:run". There is NO cross-card flow-run-id handoff: the platform flow run lives
 * entirely INSIDE this one demo run's .runtime file — the demo runId is the backend run and the platform
 * flowRunId is stored inside it, never exposed as a separate browser input (spec §2).
 *
 * Settings flow (amendment): the browser POSTs the scenario's setup values to /config, which writes them
 * to a canonical SDK config FILE (.runtime/config/flow_run.json; the service PEM → .runtime/config/keys/
 * by path). start() builds the service {@see Client} from that file via {@see Client::fromConfig}
 * (Config::fromFile) and runs OFF the config. The request body of /start is ignored; a /start with no
 * saved config → 409 not_configured.
 *
 * The GET /api/runs/{runId} poll is the drive loop AND the resume: each poll reads the platform run and,
 * if it is the company's turn, drives exactly ONE company step; otherwise it reports waiting/running and
 * touches nothing (the next poll after the person answers on their phone resumes automatically).
 */
final class Handlers implements Family
{
    public const FAMILY = 'flow';

    /** The single public scenario id (the flow family). */
    private const SCENARIO = 'flow:run';

    private const DEFAULT_API_URL = 'https://api.allme.fyi';

    /** The flow party keys the fixtures pin (spec §3). */
    private const PARTY_COMPANY = 'company';
    private const PARTY_CUSTOMER = 'customer';

    /** The canned INVALID value the validation-demo submits once for an email field (spec §2). */
    private const INVALID_EMAIL = 'not-an-email';

    /**
     * The "what just happened" trace. Every entry is `<SDK method> — <what that call did in THIS
     * scenario>`, appended AT the call site, in the order the calls were made. Keep them in step when
     * this handler changes.
     */
    private const CALL_SERVICE_BUILD = 'Client::fromConfig — builds the SERVICE-role data client from the saved config file: client credentials plus the service private key, decrypted with its passphrase';
    private const CALL_REQUEST_FIELDS = 'Client::requestFields — resolves the flow name + published version (the only handle the portal ever shows for it) to its flow id';
    private const CALL_IDENTITY = 'Client::identity — GET /api/company-data/whoami: this service\'s own company_user_id, which the COMPANY party binds to';
    private const CALL_CONNECTIONS = 'Client::connections — resolves the person\'s own share code to the connection whose id the CUSTOMER party binds to';
    private const CALL_TRIGGER = 'Client::triggerFlowRun — starts a run of the published flow for that connection, pinning the flow\'s latest published version';
    private const CALL_FLOW_RUN = 'Client::flowRun — re-read on every poll to see whose turn the run is on';
    private const CALL_PROCESS = 'Client::processFlowRun — drives ONE company step: decrypts the answers so far, fills the node, type-checks the values, encrypts a copy per party, submits — and generates the document when the submit lands on a document-mode leaf';
    private const CALL_ANSWERS = 'Client::flowRunAnswers — the completed run\'s answers, decrypted with the service key';
    private const CALL_DOCUMENT = 'Client::flowRunDocument — downloads the company\'s own copy of the generated contract and decrypts it with the service key';

    public function __construct(private readonly Runtime $rt)
    {
    }

    /** @return list<array{id:string,kind:string}> */
    public function scenarios(): array
    {
        return [['id' => self::SCENARIO, 'kind' => 'runnable']];
    }

    // ── POST /api/scenarios/{id}/config (amendment) ────────────────────────────

    /**
     * Write the browser's setup values to a canonical SDK config FILE (service role — spec §5). The
     * service PEM is written to config/keys/ and referenced by path; the demo-only run parameters (flow
     * name + published version, the person's share code, fixture choice) go to the meta sidecar so the
     * config file stays a pure SDK config the run executes off. Neither the flow id nor the connection id
     * is ever collected here — {@see start()} resolves both via the SDK instead of taking either as a
     * raw database id.
     *
     * @param array<string,mixed> $in
     */
    public function config(string $id, array $in): Response
    {
        if ($id !== self::SCENARIO) {
            return Response::json(['error' => 'not_found'], 404);
        }

        // Canonical SDK config — the service role (client_credentials + service PEM), sdk.html §2/§12c.
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
        $configPath = $this->rt->writeConfig(self::SCENARIO, $cfg);

        // Demo-only run parameters (NOT SDK Config fields) → meta sidecar.
        $this->rt->writeConfigMeta(self::SCENARIO, [
            'flow_name' => (string) ($in['flowName'] ?? ''),
            'flow_version' => (string) ($in['flowVersion'] ?? ''),
            'share_code' => (string) ($in['shareCode'] ?? ''),
            'fixture' => (string) ($in['fixture'] ?? ''),
        ]);

        return Response::json(['ok' => true, 'configPath' => $configPath]);
    }

    // ── POST /api/scenarios/{id}/start ─────────────────────────────────────────

    /**
     * Trigger the flow run. Build the service Client from the persisted config file, resolve the flow
     * name + published version and the person's share code to the ids {@see Client::triggerFlowRun}
     * needs (neither is ever collected as a raw id — spec amendment), construct the bindings via the
     * intended SDK surface (company → identity().company_user_id; customer → Connection::$personId), call
     * triggerFlowRun, and store the returned platform flowRunId in the demo run file. Returns
     * {runId, action:{"type":"none"}} — the drive happens on the GET /api/runs poll.
     */
    public function start(string $id): Response
    {
        if ($id !== self::SCENARIO) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if (!$this->rt->hasConfig(self::SCENARIO)) {
            // The run is built from the persisted config file, not the request body (amendment).
            return Response::json(['error' => 'not_configured'], 409);
        }
        $meta = $this->rt->readConfigMeta(self::SCENARIO);
        $flowName = trim((string) ($meta['flow_name'] ?? ''));
        $flowVersionRaw = trim((string) ($meta['flow_version'] ?? ''));
        $shareCode = trim((string) ($meta['share_code'] ?? ''));
        if ($flowName === '' || $flowVersionRaw === '' || $shareCode === '') {
            return Response::json(['error' => 'not_configured', 'message' => 'flow name, published version and share code are required'], 409);
        }
        if (!ctype_digit($flowVersionRaw)) {
            return Response::failure("published version \"{$flowVersionRaw}\" is not a number", 'start_failed', 400);
        }
        $flowVersion = (int) $flowVersionRaw;

        $calls = [];
        try {
            $calls[] = self::CALL_SERVICE_BUILD;
            $client = $this->serviceClient();

            // Resolve the flow name + published version to its flow id. The pair is not guaranteed
            // unique (nothing enforces it), so this can return zero, one, or more than one candidate —
            // only exactly one is safe to proceed on; anything else refuses rather than guess.
            $calls[] = self::CALL_REQUEST_FIELDS;
            $candidates = self::resolveFlowIdCandidates($client, $flowName, $flowVersion);
            if (count($candidates) === 0) {
                return Response::failure(
                    "no published flow named \"{$flowName}\" at version {$flowVersion} — check the name and the \"Published vN\" the portal shows next to it",
                    'start_failed',
                    404,
                );
            }
            if (count($candidates) > 1) {
                return Response::failure(
                    "more than one flow matches the name \"{$flowName}\" at version {$flowVersion} — rename one of them in the portal (the flow builder's name field, next to \"Published vN\") so the pair is unique, then try again",
                    'start_failed',
                    409,
                );
            }
            $flowId = $candidates[0];

            // The COMPANY party binds to this service's own company_user_id (identity()).
            $calls[] = self::CALL_IDENTITY;
            $identity = $client->identity();
            $companyUserId = (string) ($identity['company_user_id'] ?? '');
            if ($companyUserId === '') {
                return Response::failure('identity() returned no company_user_id', 'identity_error', 502);
            }

            // Resolve the person's own share code to their connection — the CUSTOMER party binds to the
            // connected person's public personId (no public user_id).
            $calls[] = self::CALL_CONNECTIONS;
            $connection = self::resolveConnection($client, $shareCode);
            if ($connection === null) {
                return Response::failure(
                    "no connection found for share code \"{$shareCode}\" — is the person connected to this service?",
                    'connection_error',
                    404,
                );
            }
            $connectionId = (string) ($connection->id ?? '');
            $personId = $connection->personId;
            if ($connectionId === '' || $personId === null || $personId === '') {
                return Response::failure(
                    "connection for share code \"{$shareCode}\" has no id/personId (not found or not connected)",
                    'connection_error',
                    502,
                );
            }

            $bindings = [
                self::PARTY_COMPANY => $companyUserId,
                self::PARTY_CUSTOMER => $personId,
            ];
            $calls[] = self::CALL_TRIGGER;
            $flowRun = $client->triggerFlowRun($flowId, $connectionId, $bindings);

            $flowRunId = (string) ($flowRun->id ?? '');
            if ($flowRunId === '') {
                return Response::failure('triggerFlowRun returned no run id', 'trigger_error', 502);
            }
        } catch (ApiError | ConfigError $e) {
            return Response::failure($e->getMessage(), 'start_failed', 502);
        }

        $runId = $this->rt->newRunId();
        $this->rt->writeRun($runId, [
            'family' => self::FAMILY,
            'scenario' => self::SCENARIO,
            'flowRunId' => $flowRunId,
            'steps' => [],
            'rejectedNodes' => [],
            'calls' => $calls,
            'completed' => false,
        ]);

        return Response::json(['runId' => $runId, 'action' => ['type' => 'none']]);
    }

    // ── GET /api/runs/{runId} ──────────────────────────────────────────────────

    /**
     * The idempotent, short-cycled poll that IS the drive loop and the resume (spec §2). Reads the
     * platform run; if it is the company's turn drives exactly ONE step; on completion fetches the answers
     * and (document-mode) downloads the generated contract. A terminal (completed) run returns its cached
     * result on every poll until TTL/Clear.
     *
     * @param array<string,mixed> $run
     */
    public function run(string $runId, array $run): Response
    {
        // Idempotent: once terminal (completed OR errored) the outcome is returned unchanged on every
        // subsequent poll — a failed run must stay failed (outer "failed"), not re-drive the platform.
        $terminal = ($run['completed'] ?? false) === true || isset($run['error']);
        if (!$terminal) {
            $run = $this->advance($run);
            $this->rt->writeRun($runId, $run);
        }

        return Response::json($this->result($run));
    }

    /**
     * One poll's worth of work. Returns the (possibly mutated) run array.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function advance(array $run): array
    {
        $flowRunId = (string) ($run['flowRunId'] ?? '');
        if ($flowRunId === '') {
            $run['status'] = 'error';
            $run['error'] = 'run has no platform flowRunId';
            return $run;
        }

        try {
            $run['calls'] = Runtime::addCall($run['calls'] ?? [], self::CALL_SERVICE_BUILD);
            $client = $this->serviceClient();
            $run['calls'] = Runtime::addCall($run['calls'] ?? [], self::CALL_FLOW_RUN);
            $flowRun = $client->flowRun($flowRunId);

            $status = (string) ($flowRun->status ?? '');
            $companyParty = $flowRun->companyPartyKey();
            $companyTurn = $companyParty !== null && $status === 'awaiting_' . $companyParty;

            if ($status === 'completed') {
                return $this->complete($run, $client, $flowRun, $flowRunId);
            }
            if ($companyTurn) {
                return $this->driveStep($run, $client, $flowRun, $flowRunId);
            }
            if (str_starts_with($status, 'awaiting_')) {
                // The person's turn (or the phone signature) — wait; the next poll resumes automatically.
                $run['status'] = 'waiting_person';
                return $run;
            }
            // Any transient in-between state (e.g. generating) — keep polling.
            $run['status'] = 'running';
            return $run;
        } catch (ApiError | ConfigError $e) {
            $run['status'] = 'error';
            $run['error'] = $e->getMessage();
            return $run;
        }
    }

    /**
     * Drive ONE company step via processFlowRun. The validation demo: for an email field whose node has
     * not yet been rejected once, fillNode returns the canned INVALID value, which processFlowRun rejects
     * with a ValidationError BEFORE any submit — recorded as accepted:false without advancing. The next
     * poll (node marked rejected) fills the VALID value → advances → accepted:true.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function driveStep(array $run, Client $client, FlowRun $flowRun, string $flowRunId): array
    {
        $nodeKey = (string) ($flowRun->currentNode ?? '');
        $rejectedNodes = array_map('strval', (array) ($run['rejectedNodes'] ?? []));

        /** @var list<array{slug:string,type:string,submitted:string}> $filled */
        $filled = [];
        $fillNode = static function (array $node, array $answers) use (&$filled, $rejectedNodes): array {
            $nk = (string) ($node['key'] ?? '');
            $fill = [];
            foreach ((array) ($node['elements'] ?? []) as $el) {
                if (!is_array($el) || ($el['kind'] ?? null) !== 'field') {
                    continue;
                }
                $slug = (string) ($el['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $ftype = (string) ($el['field_type'] ?? 'text');
                $rejectDemo = $ftype === 'email' && !in_array($nk, $rejectedNodes, true);
                $value = $rejectDemo ? self::INVALID_EMAIL : self::cannedValue($ftype);
                $fill[$slug] = $value;
                $filled[] = ['slug' => $slug, 'type' => $ftype, 'submitted' => $value];
            }
            return $fill;
        };

        $run['calls'] = Runtime::addCall($run['calls'] ?? [], self::CALL_PROCESS);
        try {
            $client->processFlowRun($flowRunId, $fillNode);
            // Advanced: every field filled for this node was accepted.
            $steps = (array) ($run['steps'] ?? []);
            foreach ($filled as $f) {
                $steps[] = [
                    'slug' => $f['slug'],
                    'type' => $f['type'],
                    'submitted' => $f['submitted'],
                    'accepted' => true,
                ];
            }
            $run['steps'] = $steps;
            $run['status'] = 'running';
            return $run;
        } catch (ValidationError $e) {
            // The canned invalid value was rejected BEFORE submit — record it and mark the node so the
            // next poll submits the valid value (spec §2). The node did NOT advance.
            $submitted = self::INVALID_EMAIL;
            foreach ($filled as $f) {
                if ($f['slug'] === $e->slug) {
                    $submitted = $f['submitted'];
                    break;
                }
            }
            $steps = (array) ($run['steps'] ?? []);
            $steps[] = [
                'slug' => (string) ($e->slug ?? ''),
                'type' => (string) ($e->fieldType ?? 'email'),
                'submitted' => $submitted,
                'accepted' => false,
                'error' => $e->getMessage(),
            ];
            $run['steps'] = $steps;
            if ($nodeKey !== '' && !in_array($nodeKey, $rejectedNodes, true)) {
                $rejectedNodes[] = $nodeKey;
            }
            $run['rejectedNodes'] = $rejectedNodes;
            $run['status'] = 'running';
            return $run;
        }
    }

    /**
     * Terminal: fetch the decrypted answers and, for a document-mode run, download the generated
     * contract's company copy (flowRunDocument — the run-scoped, service-key-decryptable surface).
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function complete(array $run, Client $client, FlowRun $flowRun, string $flowRunId): array
    {
        $run['calls'] = Runtime::addCall($run['calls'] ?? [], self::CALL_ANSWERS);
        $answers = $client->flowRunAnswers($flowRun);
        $ciphers = self::ownCipherBySlug($flowRun);
        $answersOut = [];
        foreach ($answers as $slug => $value) {
            $answersOut[] = ['slug' => (string) $slug, 'value' => $value, 'cipher' => $ciphers[$slug] ?? null];
        }
        $run['answers'] = $answersOut;

        if (($flowRun->outputMode ?? null) === 'document') {
            try {
                $run['calls'] = Runtime::addCall($run['calls'] ?? [], self::CALL_DOCUMENT);
                $bytes = $client->flowRunDocument($flowRunId);
                $run['document'] = ['status' => 'downloaded', 'downloaded' => true, 'bytes' => strlen($bytes)];
            } catch (ApiError $e) {
                // The run completed but the document is not retrievable yet — report it, don't fail.
                $run['document'] = ['status' => 'unavailable', 'downloaded' => false, 'error' => $e->getMessage()];
            }
        }

        $run['status'] = 'completed';
        $run['completed'] = true;
        return $run;
    }

    /**
     * The company's own answer rows, keyed by slug and left as the still-encrypted wrapper the API
     * returned — the evidence the "Decrypted answers" panel pairs against each cleartext value, so a
     * reader can see the decrypt actually ran on real ciphertext rather than take it on faith.
     *
     * @return array<string,mixed>
     */
    private static function ownCipherBySlug(FlowRun $flowRun): array
    {
        $serviceUid = $flowRun->serviceUserId();
        $out = [];
        foreach ($flowRun->answers as $row) {
            $slug = $row['slug'] ?? null;
            if (is_string($slug) && ($row['for_user_id'] ?? null) === $serviceUid) {
                $out[$slug] = $row['value'] ?? null;
            }
        }
        return $out;
    }

    /**
     * The GET /api/runs/{runId} response: the SHARED run envelope (CONTRACT.md — outer
     * {status:"pending"|"done"|"failed", result?, error?, calls}) with the pinned FLOW shape nested under
     * `result` ({status:"running"|"waiting_person"|"completed", steps, answers?, document?}). Progress is
     * meant to be read ONLY from `run.result`, with polling continuing ONLY while the outer status is
     * "pending", so the inner flow status must NOT sit at the top level — it drives under "pending" until
     * the platform run completes ("done") or errors ("failed").
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function result(array $run): array
    {
        $flowStatus = (string) ($run['status'] ?? 'running');
        $outer = isset($run['error']) ? 'failed' : ($flowStatus === 'completed' ? 'done' : 'pending');

        $result = [
            'status' => $flowStatus,
            'steps' => array_values((array) ($run['steps'] ?? [])),
        ];
        if (isset($run['answers'])) {
            $result['answers'] = $run['answers'];
        }
        if (isset($run['document'])) {
            $result['document'] = $run['document'];
        }

        $out = [
            'status' => $outer,
            'result' => $result,
            'calls' => array_values((array) ($run['calls'] ?? [])),
        ];
        if (isset($run['error'])) {
            $out['error'] = $run['error'];
        }
        return $out;
    }

    // ── resolving a name + code the developer can obtain into the ids the SDK needs ─

    /**
     * Resolve a flow's name + published version to its CANDIDATE flow ids. flow_id/flow_name/flow_version
     * ride the additive `.raw` dict on the flow-tagged rows requestFields() returns — they are not typed
     * properties of {@see \Allus\CompanyData\Model\RequestField}. Returns every DISTINCT flow id whose
     * tagged fields match both name and version, deduplicated — nothing here guarantees the pair is
     * unique, so the caller decides what to do with zero, one, or more than one candidate.
     *
     * @return list<string> distinct matching flow ids, in first-seen order
     */
    private static function resolveFlowIdCandidates(Client $client, string $flowName, int $flowVersion): array
    {
        $seen = [];
        foreach ($client->requestFields() as $field) {
            $raw = $field->raw;
            $name = $raw['flow_name'] ?? null;
            $version = $raw['flow_version'] ?? null;
            if ($name !== $flowName || $version === null || (int) $version !== $flowVersion) {
                continue;
            }
            $flowId = (string) ($raw['flow_id'] ?? '');
            if ($flowId !== '' && !isset($seen[$flowId])) {
                $seen[$flowId] = true;
            }
        }
        return array_keys($seen);
    }

    /**
     * Resolve a person's own share code to their {@see Connection}. connections() auto-pages the whole
     * service — a demo has too few connections for that to matter, but it is the same call a real
     * integrator would make to look a person up by the one identifier they can read off their own app.
     */
    private static function resolveConnection(Client $client, string $shareCode): ?Connection
    {
        $wanted = strtoupper($shareCode);
        foreach ($client->connections() as $connection) {
            if ($connection->shareCode !== null && strtoupper($connection->shareCode) === $wanted) {
                return $connection;
            }
        }
        return null;
    }

    // ── SDK client builder — built from the persisted config FILE (amendment) ──

    /** Build the service data client OFF the scenario's config file (service role, Config::fromFile). */
    private function serviceClient(): Client
    {
        return Client::fromConfig($this->rt->configPathFor(self::SCENARIO));
    }

    /**
     * A canned VALID plaintext for a field type (demo values over already-supported answerable types —
     * spec §3). An unknown / text type accepts anything.
     */
    private static function cannedValue(string $ftype): string
    {
        return match ($ftype) {
            'email' => 'billing@acme.example',
            'number' => '42',
            'boolean' => 'true',
            'date' => '2024-01-15',
            'date_of_birth' => '1990-05-01',
            'phone' => '+31201234567',
            'url' => 'https://acme.example',
            'address' => (string) json_encode([
                'street' => 'Herengracht 1',
                'city' => 'Amsterdam',
                'postal_code' => '1011AB',
                'country' => 'NL',
            ], JSON_UNESCAPED_SLASHES),
            default => 'Acme Corporation',
        };
    }

}
