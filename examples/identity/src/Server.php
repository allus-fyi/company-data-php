<?php

declare(strict_types=1);

namespace Allus\IdentityExample;

use Allus\CompanyData\Claim;
use Allus\CompanyData\Client;
use Allus\CompanyData\Config;
use Allus\CompanyData\Errors\ApiError;
use Allus\CompanyData\Http\CurlTransport;
use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\OAuthClient;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Session\AuthSession;

/**
 * The demo-backend contract (spec §3, config-file amendment). One class, one worker: HTTP dispatch →
 * handler → intended SDK surface (or the OIDC library for scenarios 5/6). Handlers NEVER perform raw
 * platform HTTP and NEVER block on SDK defaults — detached / challenge waits are short-cycled
 * (timeout=2) inside GET /api/runs.
 *
 * Settings flow (amendment): the browser POSTs a scenario's setup values to
 * POST /api/scenarios/{id}/config, which writes them to a canonical SDK config FILE
 * (.runtime/config/{id}.json). /start and /enroll then build the SDK from that file via the
 * role-appropriate file constructor (OAuthClient::fromConfig → Config::fromIdwFile;
 * Client::fromConfig → Config::fromFile) and run OFF the config — exactly as a real integrator wires
 * the SDK. The request body of /start is ignored; a /start with no saved config → 409 not_configured.
 */
final class Server
{
    public const CONTRACT_VERSION = 1;
    public const SDK = 'php';

    /** id => "runnable" | "guide". Scenario 7 is the guide card (no /start). */
    private const SCENARIOS = [
        1 => 'runnable', 2 => 'runnable', 3 => 'runnable', 4 => 'runnable',
        5 => 'runnable', 6 => 'runnable', 7 => 'guide', 8 => 'runnable',
    ];

    /** Scenarios that also read live values through the service data {@see Client} (service-role keys). */
    private const SERVICE_SCENARIOS = [4, 8];

    /** Scenarios that build an OAuth consent URL via {@see OAuthClient} (need the authorize base). */
    private const OAUTH_URL_SCENARIOS = [1, 2, 3, 4, 8];

    private const DEFAULT_API_URL = 'https://api.allme.fyi';
    private const DEFAULT_AUTHORIZE_BASE = OAuthClient::DEFAULT_AUTHORIZE_URL; // https://web.allme.fyi/auth

    /**
     * Network timeout (seconds) for the short-cycled polls in {@see advance()}. The SDK's poll
     * helpers bound their LOGICAL loop with timeout=2, but that does not bound the underlying HTTP
     * request — the default transport waits 30s (CurlTransport). A single-worker server must not be
     * pinned for 30s by one blackholed poll (spec §3), so the poll clients get a 2s transport.
     */
    private const POLL_TRANSPORT_TIMEOUT_S = 2.0;

    public function __construct(
        private readonly Runtime $rt,
        private readonly string $frontendDir,
        private readonly string $sdkVersion,
    ) {
    }

    // ── entry point ────────────────────────────────────────────────────────

    public function handle(): void
    {
        $this->rt->ensureDirs();
        $this->rt->sweep(); // lazy TTL sweep on every request (spec §3)

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

        try {
            if ($path === '/api/meta' && $method === 'GET') {
                $this->meta();
            } elseif ($path === '/callback' && $method === 'GET') {
                $this->callback();
            } elseif ($path === '/api/clear' && $method === 'POST') {
                $this->rt->clearAll();
                $this->json(['ok' => true]);
            } elseif (preg_match('#^/api/scenarios/(\d+)/config$#', $path, $m) && $method === 'POST') {
                $this->config((int) $m[1]);
            } elseif (preg_match('#^/api/scenarios/(\d+)/start$#', $path, $m) && $method === 'POST') {
                $this->start((int) $m[1]);
            } elseif (preg_match('#^/api/scenarios/(\d+)/enroll$#', $path, $m) && $method === 'POST') {
                $this->enroll((int) $m[1]);
            } elseif (preg_match('#^/api/scenarios/(\d+)/clear$#', $path, $m) && $method === 'POST') {
                $this->rt->clearScenario((int) $m[1]);
                $this->json(['ok' => true]);
            } elseif (preg_match('#^/api/runs/([0-9a-f]{32})$#', $path, $m) && $method === 'GET') {
                $this->run($m[1]);
            } elseif (str_starts_with($path, '/api/')) {
                $this->json(['error' => 'not_found'], 404);
            } else {
                $this->serveStatic($path);
            }
        } catch (\Throwable $e) {
            $this->json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
        }
    }

    // ── GET /api/meta ────────────────────────────────────────────────────────

    private function meta(): void
    {
        $scenarios = [];
        foreach (self::SCENARIOS as $id => $kind) {
            $scenarios[] = ['id' => $id, 'kind' => $kind];
        }
        $this->json([
            'sdk' => self::SDK,
            'sdkVersion' => $this->sdkVersion,
            'contractVersion' => self::CONTRACT_VERSION,
            'scenarios' => $scenarios,
        ]);
    }

    // ── POST /api/scenarios/{id}/config (amendment) ────────────────────────────

    /**
     * Write the browser's setup values to a canonical SDK config FILE (spec §3). Any PEM is written to
     * .runtime/config/keys/ and referenced by path; demo-only run parameters (authorize base, one_time
     * claims, share code, context) go to a meta sidecar so the config file stays a pure SDK config.
     */
    private function config(int $id): void
    {
        if (!isset(self::SCENARIOS[$id]) || self::SCENARIOS[$id] !== 'runnable') {
            $this->json(['error' => 'not_found'], 404);
            return;
        }
        $in = $this->body();

        // Canonical SDK config — the idw role for every OAuth scenario (sdk.html §12c).
        $cfg = [
            'api_url' => rtrim((string) ($in['apiUrl'] ?? '') ?: self::DEFAULT_API_URL, '/'),
            'oauth_client_id' => (string) ($in['oauthClientId'] ?? ''),
            'oauth_redirect_uri' => $this->redirectUri(),
        ];
        $secret = (string) ($in['oauthClientSecret'] ?? '');
        if ($secret !== '') {
            $cfg['oauth_client_secret'] = $secret;
        }

        // Scenario 3 (one_time): the OAuth app private key decrypts the claim values (config-only keys).
        if ($id === 3) {
            $pem = (string) ($in['oauthPrivateKeyPem'] ?? '');
            if ($pem !== '') {
                $cfg['oauth_private_key'] = $this->rt->materializeConfigKey($pem);
            }
            $pass = (string) ($in['oauthKeyPassphrase'] ?? '');
            if ($pass !== '') {
                $cfg['oauth_key_passphrase'] = $pass;
            }
        }

        // Scenarios 4/8 also read live values via the service data Client — add the service-role keys to
        // the SAME file (role only decides which fields are REQUIRED; Config loads whatever is present).
        if (in_array($id, self::SERVICE_SCENARIOS, true)) {
            $cfg['client_id'] = (string) ($in['clientId'] ?? '');
            $cfg['client_secret'] = (string) ($in['clientSecret'] ?? '');
            $sPem = (string) ($in['servicePrivateKeyPem'] ?? '');
            if ($sPem !== '') {
                $cfg['service_private_key'] = $this->rt->materializeConfigKey($sPem);
            }
            $cfg['key_passphrase'] = (string) ($in['keyPassphrase'] ?? '');
        }

        $configPath = $this->rt->writeConfig($id, $cfg);

        // Demo-only run parameters (NOT SDK Config fields) → meta sidecar.
        $meta = [];
        if (in_array($id, self::OAUTH_URL_SCENARIOS, true)) {
            $meta['authorize_base'] = (string) ($in['authorizeBase'] ?? '') ?: self::DEFAULT_AUTHORIZE_BASE;
        }
        if ($id === 3) {
            $meta['claims'] = $this->claims($in);
        }
        if ($id === 8) {
            $meta['share_code'] = (string) ($in['shareCode'] ?? '');
            if (isset($in['context']) && $in['context'] !== '') {
                $meta['context'] = (string) $in['context'];
            }
        }
        $this->rt->writeConfigMeta($id, $meta);

        $this->json(['ok' => true, 'configPath' => $configPath]);
    }

    // ── POST /api/scenarios/{id}/start ─────────────────────────────────────────

    private function start(int $id): void
    {
        if (!isset(self::SCENARIOS[$id]) || self::SCENARIOS[$id] !== 'runnable') {
            $this->json(['error' => 'not_found'], 404);
            return;
        }
        if (!$this->rt->hasConfig($id)) {
            // The run is built from the persisted config file, not the request body (amendment).
            $this->json(['error' => 'not_configured'], 409);
            return;
        }
        $runId = $this->rt->newRunId();
        $run = ['scenario' => $id, 'status' => 'pending', 'state' => $runId, 'calls' => []];

        switch ($id) {
            case 1: // Sign in — redirect
            case 3: // One-time claims
            case 4: // Connect (stay-connected)
                $pkce = Pkce::generate();
                $run['verifier'] = $pkce['verifier'];
                $mode = $id === 1 ? 'signin' : ($id === 3 ? 'one_time' : 'connect');
                $claims = $id === 3
                    ? $this->claimObjects($this->rt->readConfigMeta($id)['claims'] ?? [])
                    : [];
                $oauth = $this->oauthClientFor($id);
                // redirectUri = null → the OAuthClient uses its config's oauth_redirect_uri.
                $url = $oauth->authorizeUrl($mode, $claims, $runId, 'redirect', $pkce['challenge']);
                $run['calls'] = ['OAuthClient::authorizeUrl'];
                $this->rt->writeRun($runId, $run);
                $this->json(['runId' => $runId, 'action' => ['type' => 'redirect', 'url' => $url]]);
                return;

            case 2: // Sign in — detached
                $pkce = Pkce::generate();
                $run['verifier'] = $pkce['verifier'];
                $run['wait'] = 'detached_signin';
                $oauth = $this->oauthClientFor($id);
                $url = $oauth->authorizeUrl('signin', [], $runId, 'detached', $pkce['challenge']);
                $run['calls'] = ['OAuthClient::authorizeUrl'];
                $this->rt->writeRun($runId, $run);
                $this->json(['runId' => $runId, 'action' => ['type' => 'detached', 'url' => $url]]);
                return;

            case 5: // OIDC login
            case 6: // OIDC — continue on your phone (#431)
                $pkce = Pkce::generate();
                $nonce = bin2hex(random_bytes(16));
                $run['verifier'] = $pkce['verifier'];
                $run['nonce'] = $nonce;
                [$oidcClient, $authService] = $this->oidcClientFor($id);
                $url = $authService->getAuthorizationUri($oidcClient, [
                    'state' => $runId,
                    'nonce' => $nonce,
                    'scope' => 'openid profile email',
                    'redirect_uri' => $this->configRedirectUri($id),
                    'code_challenge' => $pkce['challenge'],
                    'code_challenge_method' => 'S256',
                ]);
                $run['calls'] = ['(oidc) IssuerBuilder::build', '(oidc) AuthorizationService::getAuthorizationUri'];
                $this->rt->writeRun($runId, $run);
                $this->json(['runId' => $runId, 'action' => ['type' => 'redirect', 'url' => $url]]);
                return;

            case 8: // Standalone service-2FA — the challenge step
                $meta = $this->rt->readConfigMeta($id);
                $shareCode = (string) ($meta['share_code'] ?? '');
                $context = isset($meta['context']) && $meta['context'] !== '' ? (string) $meta['context'] : null;
                $idempotencyKey = substr('demo-' . $runId, 0, 64); // backend-generated, per-run (SDK requires it)
                $run['challengeIdemKey'] = $idempotencyKey;
                $run['wait'] = 'challenge';
                $client = $this->serviceClientFor($id);
                $challenge = $client->twoFactor()->challenge($shareCode, $idempotencyKey, $context);
                $run['challengeId'] = $challenge->challengeId;
                $run['calls'] = ['Client::twoFactor', 'TwoFactorClient::challenge'];
                $this->rt->writeRun($runId, $run);
                $this->json([
                    'runId' => $runId,
                    'action' => ['type' => 'challenge', 'matchingDigits' => $challenge->matchingDigits],
                ]);
                return;
        }
    }

    // ── POST /api/scenarios/{id}/enroll (scenario 8) ───────────────────────────

    private function enroll(int $id): void
    {
        if ($id !== 8) {
            $this->json(['error' => 'not_found'], 404);
            return;
        }
        if (!$this->rt->hasConfig($id)) {
            $this->json(['error' => 'not_configured'], 409);
            return;
        }
        $in = $this->body();
        $responseMode = ($in['responseMode'] ?? 'redirect') === 'detached' ? 'detached' : 'redirect';
        $runId = $this->rt->newRunId();

        $oauth = $this->oauthClientFor($id);
        $url = $oauth->authorizeUrl('2fa_enroll', [], $runId, $responseMode);

        $run = [
            'scenario' => 8,
            'isEnroll' => true,
            'status' => 'pending',
            'state' => $runId,
            'calls' => ['OAuthClient::authorizeUrl'],
            'wait' => $responseMode === 'detached' ? 'detached_enroll' : 'enroll_redirect',
        ];
        $this->rt->writeRun($runId, $run);

        $action = $responseMode === 'detached'
            ? ['type' => 'detached', 'url' => $url]
            : ['type' => 'redirect', 'url' => $url];
        $this->json(['runId' => $runId, 'action' => $action]);
    }

    // ── GET /callback ──────────────────────────────────────────────────────────

    private function callback(): void
    {
        $q = $_GET;
        $state = (string) ($q['state'] ?? '');
        $run = $this->rt->readRun($state);
        if ($run === null) {
            header('Location: /?error=unknown_run', true, 302);
            return;
        }
        $id = (int) ($run['scenario'] ?? 0);

        try {
            if (($q['enrolled'] ?? '') === 'true') {
                // Redirect-leg enrollment outcome (#436) — nothing to exchange; record it.
                $run['status'] = 'done';
                $run['result'] = ['enrolled' => true];
                $run['calls'][] = 'callback(enrolled=true)';
            } elseif (isset($q['code']) && $q['code'] !== '') {
                $code = (string) $q['code'];
                if ($id === 5 || $id === 6) {
                    $run = $this->completeOidc($run, $code);
                } else {
                    $run = $this->completeSignin($run, $code);
                }
            } else {
                $run['status'] = 'failed';
                $run['error'] = 'callback missing code / enrolled';
            }
        } catch (\Throwable $e) {
            $run['status'] = 'failed';
            $run['error'] = $e->getMessage();
        }

        $this->rt->writeRun($state, $run);
        header('Location: /?scenario=' . $id . '&run=' . rawurlencode($state), true, 302);
    }

    // ── GET /api/runs/{runId} ──────────────────────────────────────────────────

    private function run(string $runId): void
    {
        $run = $this->rt->readRun($runId);
        if ($run === null) {
            $this->json(['error' => 'not_found'], 404);
            return;
        }

        // Idempotent: a terminal outcome is returned on every poll until TTL/Clear.
        if (($run['status'] ?? 'pending') === 'pending') {
            $run = $this->advance($run);
            $this->rt->writeRun($runId, $run);
        }

        $out = ['status' => $run['status'] ?? 'pending', 'calls' => $run['calls'] ?? []];
        if (isset($run['result'])) {
            $out['result'] = $run['result'];
        }
        if (isset($run['error'])) {
            $out['error'] = $run['error'];
        }
        $this->json($out);
    }

    /**
     * Short-cycled advance for a pending run awaiting a detached / challenge outcome. ONE SDK wait with
     * timeout=2 per poll; an SDK timeout is treated as still-pending. Clients are rebuilt from the run's
     * scenario config file (amendment) — the run stores no credentials.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function advance(array $run): array
    {
        $wait = $run['wait'] ?? null;
        $id = (int) ($run['scenario'] ?? 0);
        try {
            if ($wait === 'detached_signin') {
                $oauth = $this->oauthClientFor($id, self::POLL_TRANSPORT_TIMEOUT_S);
                $body = $oauth->pollResult((string) $run['state'], 2, 2); // loop timeout=2, 2s transport
                $run['calls'][] = 'OAuthClient::pollResult';
                $code = (string) ($body['code'] ?? '');
                if ($code !== '') {
                    $run = $this->completeSignin($run, $code);
                }
            } elseif ($wait === 'detached_enroll') {
                $oauth = $this->oauthClientFor($id, self::POLL_TRANSPORT_TIMEOUT_S);
                $body = $oauth->pollResult((string) $run['state'], 2, 2);
                $run['calls'][] = 'OAuthClient::pollResult';
                if (!empty($body['enrolled'])) {
                    $run['status'] = 'done';
                    $run['result'] = ['enrolled' => true];
                }
            } elseif ($wait === 'challenge') {
                $client = $this->serviceClientFor($id, self::POLL_TRANSPORT_TIMEOUT_S);
                $res = $client->twoFactor()->waitForResult((string) $run['challengeId'], 2, 2);
                $run['calls'][] = 'TwoFactorClient::waitForResult';
                $run['status'] = 'done';
                $run['result'] = ['status' => $res->status, 'completed_at' => $res->completedAt];
            }
            // else (redirect / continue-on-phone flows): completion arrives via /callback — stay pending.
        } catch (ApiError $e) {
            // The SDK poll helpers signal a LOGICAL "not completed within {n}s" timeout as
            // ApiError(0) with that exact sentinel message (OAuthClient::pollResult /
            // TwoFactorClient::waitForResult). A real transport failure ALSO surfaces as ApiError(0)
            // (CurlTransport), so the status alone cannot tell them apart — match the SDK's sentinel.
            // Only the logical timeout is "still pending"; a real network/transport failure is a
            // failed run (spec §3), not an eternal pending.
            if ($e->status === 0 && str_contains($e->getMessage(), 'not completed within')) {
                return $run; // logical short-cycle timeout → still pending
            }
            $run['status'] = 'failed';
            $run['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $run['status'] = 'failed';
            $run['error'] = $e->getMessage();
        }
        return $run;
    }

    // ── SDK / OIDC completion helpers ──────────────────────────────────────────

    /**
     * Complete a redirect / detached SIGN-IN (scenarios 1, 2, 3, 4): pollResult already gave the code
     * for detached; here exchange + read identity via completeSignIn, and for connect read live values.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function completeSignin(array $run, string $code): array
    {
        $id = (int) ($run['scenario'] ?? 0);
        $oauth = $this->oauthClientFor($id);
        $out = $oauth->completeSignIn($code, $run['verifier'] ?? null);
        $run['calls'][] = 'OAuthClient::completeSignIn';
        $result = [
            'user' => $out['user'] ?? null,
            'mode' => $out['mode'] ?? null,
            'two_factor' => $out['two_factor'] ?? false,
            'values' => $out['values'] ?? [],
        ];

        if ($id === 4) {
            // Connect: read the person's LIVE values via the service data client.
            $shareCode = (string) ($out['user']['share_code'] ?? '');
            $client = $this->serviceClientFor($id);
            $live = [];
            foreach ($client->connections() as $conn) {
                if ($shareCode !== '' && $conn->shareCode === $shareCode) {
                    $live = $conn->values;
                    break;
                }
            }
            $run['calls'][] = 'Client::connections';
            $result['live_values'] = $live;
        }

        $run['status'] = 'done';
        $run['result'] = $result;
        return $run;
    }

    /**
     * Complete an OIDC sign-in (scenarios 5/6) via the third-party OIDC library — id_token verified.
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function completeOidc(array $run, string $code): array
    {
        $id = (int) ($run['scenario'] ?? 0);
        [$oidcClient, $authService] = $this->oidcClientFor($id);
        $session = AuthSession::fromArray([
            'state' => (string) $run['state'],
            'nonce' => (string) ($run['nonce'] ?? ''),
            'code_verifier' => (string) ($run['verifier'] ?? ''),
        ]);
        $tokenSet = $authService->callback(
            $oidcClient,
            ['code' => $code, 'state' => (string) $run['state']],
            $this->configRedirectUri($id),
            $session,
        );
        $run['calls'][] = '(oidc) AuthorizationService::callback';
        $run['status'] = 'done';
        $run['result'] = ['claims' => $tokenSet->claims()];
        return $run;
    }

    // ── SDK / OIDC client builders — built from the persisted config FILE (amendment) ──

    /**
     * Build the OAuth client OFF the scenario's config file via the idw file constructor. The named
     * OAuthClient::fromConfig() is used for the default (deployed) authorize base — the acceptance path;
     * a non-default authorize base (local-stack option) still loads Config from the file via
     * Config::fromIdwFile, only supplying the alternate base the wrapper cannot set.
     *
     * $transportTimeout bounds the HTTP network wait — passed for the short-cycled polls so one
     * blackholed request cannot pin the single worker for the transport's 30s default (spec §3);
     * null keeps the SDK's own default transport for the completion paths.
     */
    private function oauthClientFor(int $id, ?float $transportTimeout = null): OAuthClient
    {
        $path = $this->rt->configPathFor($id);
        $transport = $transportTimeout !== null ? new CurlTransport($transportTimeout) : null;
        $base = (string) ($this->rt->readConfigMeta($id)['authorize_base'] ?? '');
        if ($base === '' || $base === OAuthClient::DEFAULT_AUTHORIZE_URL) {
            return OAuthClient::fromConfig($path, $transport); // null → the SDK's default CurlTransport
        }
        return new OAuthClient(Config::fromIdwFile($path), $transport ?? new CurlTransport(), authorizeBase: $base);
    }

    /**
     * Build the service data client OFF the scenario's config file (service role). $transportTimeout
     * bounds the HTTP network wait for the short-cycled challenge poll (same reason as above).
     */
    private function serviceClientFor(int $id, ?float $transportTimeout = null): Client
    {
        $path = $this->rt->configPathFor($id);
        if ($transportTimeout === null) {
            return Client::fromConfig($path);
        }
        $cfg = Config::fromFile($path);
        return new Client($cfg, new HttpClient($cfg, new CurlTransport($transportTimeout)));
    }

    /**
     * Build the OIDC client + authorization service (the #314 compliance surface) from the config file.
     *
     * @return array{0:\Facile\OpenIDClient\Client\ClientInterface,1:\Facile\OpenIDClient\Service\AuthorizationService}
     */
    private function oidcClientFor(int $id): array
    {
        $cfg = $this->loadConfig($id);
        // Non-default issuer tolerance: discovery is driven off the configured api base.
        $issuer = (new IssuerBuilder())->build((string) ($cfg['api_url'] ?? ''));
        $metadata = ClientMetadata::fromArray([
            'client_id' => (string) ($cfg['oauth_client_id'] ?? ''),
            'client_secret' => (string) ($cfg['oauth_client_secret'] ?? ''),
            'redirect_uris' => [(string) ($cfg['oauth_redirect_uri'] ?? '')],
            'token_endpoint_auth_method' => 'client_secret_post', // the token endpoint's only method
            'response_types' => ['code'],
        ]);
        $client = (new ClientBuilder())->setIssuer($issuer)->setClientMetadata($metadata)->build();
        $authService = (new AuthorizationServiceBuilder())->build();
        return [$client, $authService];
    }

    /**
     * The decoded canonical config file for a scenario ({} if none).
     *
     * @return array<string,mixed>
     */
    private function loadConfig(int $id): array
    {
        $raw = @file_get_contents($this->rt->configPathFor($id));
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** The redirect URI recorded in the scenario's config file (used by the OIDC library). */
    private function configRedirectUri(int $id): string
    {
        return (string) ($this->loadConfig($id)['oauth_redirect_uri'] ?? $this->redirectUri());
    }

    // ── input / config plumbing ────────────────────────────────────────────────

    /** The registered redirect URI: http://{host}/callback (host = the serving origin). */
    private function redirectUri(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? ('localhost:' . (getenv('PORT') ?: '8091')));
        return 'http://' . $host . '/callback';
    }

    /**
     * @param array<string,mixed> $in
     * @return list<string>
     */
    private function claims(array $in): array
    {
        $raw = $in['claims'] ?? null;
        if (is_array($raw) && $raw !== []) {
            return array_values(array_map('strval', $raw));
        }
        return ['email', 'phone']; // a small default claim set (spec §4 scenario 3)
    }

    /**
     * @param list<string> $types
     * @return list<Claim>
     */
    private function claimObjects(array $types): array
    {
        return array_map(static fn (string $t): Claim => new Claim($t), $types);
    }

    // ── HTTP plumbing ──────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private function serveStatic(string $path): void
    {
        $rel = $path === '/' ? '/index.html' : $path;
        $full = realpath($this->frontendDir . $rel);
        $root = realpath($this->frontendDir);

        // Path-traversal guard + SPA fallback to index.html.
        if ($full === false || $root === false || !str_starts_with($full, $root) || !is_file($full)) {
            $index = $this->frontendDir . '/index.html';
            if (is_file($index)) {
                header('Content-Type: text/html; charset=utf-8');
                readfile($index);
                return;
            }
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "bundle not found";
            return;
        }

        header('Content-Type: ' . self::mime($full));
        readfile($full);
    }

    private static function mime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html' => 'text/html; charset=utf-8',
            'js', 'mjs' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'json', 'map' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
