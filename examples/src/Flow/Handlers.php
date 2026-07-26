<?php

declare(strict_types=1);

namespace Allus\Examples\Flow;

use Allus\CompanyData\Client;
use Allus\CompanyData\Errors\ApiError;
use Allus\CompanyData\Errors\ConfigError;
use Allus\CompanyData\Errors\ValidationError;
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
     * service PEM is written to config/keys/ and referenced by path; the demo-only run parameters
     * (published flow id, connection id, fixture choice) go to the meta sidecar so the config file stays a
     * pure SDK config the run executes off.
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
            'flow_id' => (string) ($in['flowId'] ?? ''),
            'connection_id' => (string) ($in['connectionId'] ?? ''),
            'fixture' => (string) ($in['fixture'] ?? ''),
        ]);

        return Response::json(['ok' => true, 'configPath' => $configPath]);
    }

    // ── POST /api/scenarios/{id}/start ─────────────────────────────────────────

    /**
     * Trigger the flow run. Build the service Client from the persisted config file, construct the
     * bindings via the intended SDK surface (company → identity().company_user_id; customer →
     * Connection::$personId), call triggerFlowRun, and store the returned platform flowRunId in the demo
     * run file. Returns {runId, action:{"type":"none"}} — the drive happens on the GET /api/runs poll.
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
        $flowId = (string) ($meta['flow_id'] ?? '');
        $connectionId = (string) ($meta['connection_id'] ?? '');
        if ($flowId === '' || $connectionId === '') {
            return Response::json(['error' => 'not_configured', 'message' => 'flow id and connection id are required'], 409);
        }

        $calls = [];
        try {
            $client = $this->serviceClient();

            // The COMPANY party binds to this service's own company_user_id (#491 identity()).
            $identity = $client->identity();
            $calls[] = 'Client::identity';
            $companyUserId = (string) ($identity['company_user_id'] ?? '');
            if ($companyUserId === '') {
                return Response::json(['error' => 'identity_error', 'message' => 'identity() returned no company_user_id'], 502);
            }

            // The CUSTOMER party binds to the connected person's public personId (no public user_id).
            $connection = $client->connection($connectionId);
            $calls[] = 'Client::connection';
            $personId = $connection->personId;
            if ($personId === null || $personId === '') {
                return Response::json([
                    'error' => 'connection_error',
                    'message' => "connection {$connectionId} has no personId (not found or not connected)",
                ], 502);
            }

            $bindings = [
                self::PARTY_COMPANY => $companyUserId,
                self::PARTY_CUSTOMER => $personId,
            ];
            $flowRun = $client->triggerFlowRun($flowId, $connectionId, $bindings);
            $calls[] = 'Client::triggerFlowRun';

            $flowRunId = (string) ($flowRun->id ?? '');
            if ($flowRunId === '') {
                return Response::json(['error' => 'trigger_error', 'message' => 'triggerFlowRun returned no run id'], 502);
            }
        } catch (ApiError | ConfigError $e) {
            return Response::json(['error' => 'start_failed', 'message' => $e->getMessage()], 502);
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
            $client = $this->serviceClient();
            $flowRun = $client->flowRun($flowRunId);
            $run['calls'] = $this->addCall($run['calls'] ?? [], 'Client::flowRun');

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

        try {
            $client->processFlowRun($flowRunId, $fillNode);
            $run['calls'] = $this->addCall($run['calls'] ?? [], 'Client::processFlowRun');
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
            $run['calls'] = $this->addCall($run['calls'] ?? [], 'Client::processFlowRun');
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
     * Terminal: fetch the decrypted answers (#491) and, for a document-mode run, download the generated
     * contract's company copy (#491 flowRunDocument — the run-scoped, service-key-decryptable surface).
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function complete(array $run, Client $client, FlowRun $flowRun, string $flowRunId): array
    {
        $answers = $client->flowRunAnswers($flowRun);
        $run['calls'] = $this->addCall($run['calls'] ?? [], 'Client::flowRunAnswers');
        $answersOut = [];
        foreach ($answers as $slug => $value) {
            $answersOut[] = ['slug' => (string) $slug, 'value' => $value];
        }
        $run['answers'] = $answersOut;

        if (($flowRun->outputMode ?? null) === 'document') {
            try {
                $bytes = $client->flowRunDocument($flowRunId);
                $run['calls'] = $this->addCall($run['calls'] ?? [], 'Client::flowRunDocument');
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
     * The GET /api/runs/{runId} response: the SHARED run envelope (CONTRACT.md — outer
     * {status:"pending"|"done"|"failed", result?, error?, calls}) with the pinned FLOW shape nested under
     * `result` ({status:"running"|"waiting_person"|"completed", steps, answers?, document?}). The shared
     * frontend reads progress ONLY from `run.result` and keeps polling ONLY while the outer status is
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

    /**
     * Append a call name preserving first-occurrence order (a poll may repeat flowRun across polls).
     *
     * @param list<string>|array<int,mixed> $calls
     * @return list<string>
     */
    private function addCall(array $calls, string $name): array
    {
        $calls = array_map('strval', array_values($calls));
        if (!in_array($name, $calls, true)) {
            $calls[] = $name;
        }
        return $calls;
    }
}
