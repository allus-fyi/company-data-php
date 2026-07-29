<?php

declare(strict_types=1);

namespace Allus\Examples\Identity;

use Allus\CompanyData\Claim;
use Allus\CompanyData\Client;
use Allus\CompanyData\Config;
use Allus\CompanyData\Errors\ApiError;
use Allus\CompanyData\Http\CurlTransport;
use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\OAuthClient;
use Allus\Examples\Family;
use Allus\Examples\Pkce;
use Allus\Examples\Response;
use Allus\Examples\Runtime;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Session\AuthSession;

/**
 * The IDENTITY scenario handlers (spec §3, config-file amendment): Sign in with allme (redirect / detached
 * / one-time claims / connect), OIDC login, and standalone service-2FA. Each
 * handler runs the intended SDK surface (or the OIDC library for scenario 5). Handlers NEVER perform
 * raw platform HTTP and NEVER block on SDK defaults — detached / challenge waits are short-cycled
 * (timeout=2) inside {@see run()}.
 *
 * Settings flow (amendment): the browser POSTs a scenario's setup values to /config, which writes them to
 * a canonical SDK config FILE (.runtime/config/{id}.json). start()/enroll() then build the SDK from that
 * file via the role-appropriate file constructor (OAuthClient::fromConfig → Config::fromIdwFile;
 * Client::fromConfig → Config::fromFile) and run OFF the config — exactly as a real integrator wires the
 * SDK. The request body of /start is ignored; a /start with no saved config → 409 not_configured.
 */
final class Handlers implements Family
{
    public const FAMILY = 'identity';

    /** id => "runnable" | "guide". Scenario 7 is the guide card (no /start). */
    private const SCENARIOS = [
        1 => 'runnable', 2 => 'runnable', 3 => 'runnable', 4 => 'runnable',
        5 => 'runnable', 7 => 'guide', 8 => 'runnable',
    ];

    /** Scenarios that also read live values through the service data {@see Client} (service-role keys). */
    private const SERVICE_SCENARIOS = [4, 8];

    /**
     * Scenarios whose {@see OAuthClient::completeSignIn()} response can carry claim values (userinfo
     * `values` non-empty) and therefore need the OAuth app private key configured to decrypt them: mode
     * one_time and mode connect, both delivered as app-key ciphertext through userinfo. Mode signin
     * (scenarios 1, 2) never carries values; scenario 8 never calls this leg at all; scenario 5 runs the
     * third-party OIDC library instead of this SDK's decrypt path.
     */
    private const CLAIM_VALUE_SCENARIOS = [3, 4];

    /** Scenarios that build an OAuth consent URL via {@see OAuthClient} (need the authorize base). */
    private const OAUTH_URL_SCENARIOS = [1, 2, 3, 4, 8];

    private const DEFAULT_API_URL = 'https://api.allme.fyi';
    private const DEFAULT_AUTHORIZE_BASE = OAuthClient::DEFAULT_AUTHORIZE_URL; // https://web.allme.fyi/auth

    /**
     * Refusal when the request carries no Host header, so the browser's origin is unknown. There is
     * NO default host: substituting one (localhost) silently sends the round-trip to a DIFFERENT origin
     * than the browser is on — a different localStorage and a redirect URI the OAuth app never registered.
     */
    private const NO_ORIGIN = 'no_origin — this request carried no Host header, so the OAuth redirect URI '
        . 'cannot be derived from the origin your browser is using. Open the example by its address '
        . '(http://<host>:<port>/) and save the setup again.';

    /**
     * Network timeout (seconds) for the short-cycled polls in {@see advance()}. The SDK's poll helpers
     * bound their LOGICAL loop with timeout=2, but that does not bound the underlying HTTP request — the
     * default transport waits 30s (CurlTransport). A single-worker server must not be pinned for 30s by
     * one blackholed poll (spec §3), so the poll clients get a 2s transport.
     */
    private const POLL_TRANSPORT_TIMEOUT_S = 2.0;

    /**
     * The "what just happened" trace. Every entry is `<SDK method> — <what that call did in THIS
     * scenario>`, appended AT the call site, in the order the calls were made; an entry wrapped in
     * parentheses is a step that is deliberately NOT an SDK call. Keep them in step when this handler
     * changes: the panel is headed "What just happened", and a list that no longer matches the code is
     * worse than a short one.
     */
    private const CALL_IDW_BUILD = 'OAuthClient::fromConfig — builds the RP client from the saved config file: client id, secret and the registered redirect URI';
    private const CALL_IDW_BUILD_LOCAL = 'new OAuthClient(Config::fromIdwFile(…)) — builds the RP client from the saved config file: client id, secret and the registered redirect URI';
    private const CALL_AUTH_SIGNIN = 'OAuthClient::authorizeUrl — the consent URL the person is sent to (mode signin, response_mode redirect, PKCE S256, state = this run id)';
    private const CALL_AUTH_SIGNIN_DETACHED = 'OAuthClient::authorizeUrl — the sign-in URL behind the link + QR (mode signin, response_mode detached, PKCE S256, state = this run id)';
    private const CALL_AUTH_ONE_TIME = 'OAuthClient::authorizeUrl — the consent URL the person is sent to (mode one_time, claims email + phone, PKCE S256, state = this run id)';
    private const CALL_AUTH_CONNECT = 'OAuthClient::authorizeUrl — the consent URL the person is sent to (mode connect, PKCE S256, state = this run id)';
    private const CALL_AUTH_ENROLL = 'OAuthClient::authorizeUrl — the enrollment URL the person is sent to (mode 2fa_enroll, response_mode redirect)';
    private const CALL_AUTH_ENROLL_DETACHED = 'OAuthClient::authorizeUrl — the enrollment URL behind the link + QR (mode 2fa_enroll, response_mode detached)';
    private const CALL_POLL_SIGNIN = 'OAuthClient::pollResult — polls POST /oauth2/result until the phone delivers the code (one 2s-bounded call per browser poll)';
    private const CALL_POLL_ENROLL = 'OAuthClient::pollResult — polls POST /oauth2/result until the phone delivers {enrolled: true} (one 2s-bounded call per browser poll)';
    private const CALL_COMPLETE_SIGNIN = 'OAuthClient::completeSignIn — exchanges the code + PKCE verifier at POST /oauth2/token, then reads GET /api/oauth/userinfo; mode signin returns the identity only, no claim values';
    private const CALL_COMPLETE_ONE_TIME = 'OAuthClient::completeSignIn — exchanges the code + PKCE verifier at POST /oauth2/token, reads GET /api/oauth/userinfo, and decrypts every claim value with the OAuth app private key';
    private const CALL_COMPLETE_CONNECT = 'OAuthClient::completeSignIn — exchanges the code + PKCE verifier at POST /oauth2/token, reads GET /api/oauth/userinfo, and decrypts the consented claim values with the OAuth app private key; the connection\'s live values still come separately from the data client below';
    private const CALL_ENROLLED_CALLBACK = '(callback ?enrolled=true) — the redirect-leg enrollment outcome; there is nothing to exchange, so no further SDK call';
    private const CALL_SERVICE_BUILD = 'Client::fromConfig — builds the SERVICE-role data client from the saved config file: client credentials plus the service private key, decrypted with its passphrase';
    private const CALL_CONNECTIONS_LIVE = 'Client::connections — pages GET /api/company-data/connections and decrypts each person\'s values with the service key; the run keeps the one whose share code just signed in';
    private const CALL_TWO_FACTOR = 'Client::twoFactor — the service-2FA sub-client, on the same data-client credentials';
    private const CALL_CHALLENGE = 'TwoFactorClient::challenge — POST /api/service-2fa/challenges for the person\'s share code with a per-run idempotency key; returns the challenge id, plus matching digits when the service has number matching on';
    private const CALL_WAIT_RESULT = 'TwoFactorClient::waitForResult — polls GET /api/service-2fa/challenges/{id} until the status leaves pending: approved, denied, expired or revoked (one 2s-bounded call per browser poll; the first terminal read burns the result)';
    private const CALL_OIDC_DISCOVERY = '(oidc) IssuerBuilder::build — discovery: fetches /.well-known/openid-configuration from the configured API base';
    private const CALL_OIDC_AUTH_URL = '(oidc) AuthorizationService::getAuthorizationUri — the authorization URL (scope openid profile email, PKCE S256, nonce, state = this run id)';
    private const CALL_OIDC_COMPLETE = '(oidc) AuthorizationService::callback — exchanges the code at the discovered token endpoint (client_secret_post + PKCE verifier), then verifies the id_token against the JWKS: signature, issuer, audience and nonce; the claims shown are that verified token\'s';

    public function __construct(private readonly Runtime $rt)
    {
    }

    /** @return list<array{id:int,kind:string}> */
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
     * Write the browser's setup values to a canonical SDK config FILE (spec §3). Any PEM is written to
     * .runtime/config/keys/ and referenced by path; demo-only run parameters (authorize base, one_time
     * claims, share code, context) go to a meta sidecar so the config file stays a pure SDK config.
     *
     * @param array<string,mixed> $in
     */
    public function config(string $sid, array $in): Response
    {
        $id = (int) $sid;
        if (!isset(self::SCENARIOS[$id]) || self::SCENARIOS[$id] !== 'runnable') {
            return Response::json(['error' => 'not_found'], 404);
        }
        // The redirect URI is derived from THIS request's origin and from nothing else. Refuse
        // rather than invent a host: the suite renders this sentence on Save.
        if (self::requestHost() === '') {
            return Response::json(['error' => self::NO_ORIGIN], 400);
        }

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

        // Any scenario whose run can carry claim values (self::CLAIM_VALUE_SCENARIOS) needs the OAuth
        // app private key to decrypt them (config-only keys).
        if (in_array($id, self::CLAIM_VALUE_SCENARIOS, true)) {
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

        $configPath = $this->rt->writeConfig($sid, $cfg);

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
        $this->rt->writeConfigMeta($sid, $meta);

        return Response::json(['ok' => true, 'configPath' => $configPath]);
    }

    // ── POST /api/scenarios/{id}/start ─────────────────────────────────────────

    public function start(string $sid): Response
    {
        $id = (int) $sid;
        if (!isset(self::SCENARIOS[$id]) || self::SCENARIOS[$id] !== 'runnable') {
            return Response::json(['error' => 'not_found'], 404);
        }
        if (!$this->rt->hasConfig($sid)) {
            // The run is built from the persisted config file, not the request body (amendment).
            return Response::json(['error' => 'not_configured'], 409);
        }
        $runId = $this->rt->newRunId();
        $run = ['family' => self::FAMILY, 'scenario' => $id, 'status' => 'pending', 'state' => $runId, 'calls' => []];

        switch ($id) {
            case 1: // Sign in — redirect
            case 3: // One-time claims
            case 4: // Connect (stay-connected)
                $pkce = Pkce::generate();
                $run['verifier'] = $pkce['verifier'];
                $mode = $id === 1 ? 'signin' : ($id === 3 ? 'one_time' : 'connect');
                $claims = $id === 3
                    ? $this->claimObjects($this->rt->readConfigMeta($sid)['claims'] ?? [])
                    : [];
                $run['calls'] = [$this->idwBuildCall($id), match ($id) {
                    3 => self::CALL_AUTH_ONE_TIME,
                    4 => self::CALL_AUTH_CONNECT,
                    default => self::CALL_AUTH_SIGNIN,
                }];
                $oauth = $this->oauthClientFor($id);
                // redirectUri = null → the OAuthClient uses its config's oauth_redirect_uri.
                $url = $oauth->authorizeUrl($mode, $claims, $runId, 'redirect', $pkce['challenge']);
                $this->rt->writeRun($runId, $run);
                return Response::json(['runId' => $runId, 'action' => ['type' => 'redirect', 'url' => $url]]);

            case 2: // Sign in — detached
                $pkce = Pkce::generate();
                $run['verifier'] = $pkce['verifier'];
                $run['wait'] = 'detached_signin';
                $run['calls'] = [$this->idwBuildCall($id), self::CALL_AUTH_SIGNIN_DETACHED];
                $oauth = $this->oauthClientFor($id);
                $url = $oauth->authorizeUrl('signin', [], $runId, 'detached', $pkce['challenge']);
                $this->rt->writeRun($runId, $run);
                return Response::json(['runId' => $runId, 'action' => ['type' => 'detached', 'url' => $url]]);

            case 5: // OIDC login
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
                $run['calls'] = [self::CALL_OIDC_DISCOVERY, self::CALL_OIDC_AUTH_URL];
                $this->rt->writeRun($runId, $run);
                return Response::json(['runId' => $runId, 'action' => ['type' => 'redirect', 'url' => $url]]);

            case 8: // Standalone service-2FA — the challenge step
                $meta = $this->rt->readConfigMeta($sid);
                $shareCode = (string) ($meta['share_code'] ?? '');
                $context = isset($meta['context']) && $meta['context'] !== '' ? (string) $meta['context'] : null;
                $idempotencyKey = substr('demo-' . $runId, 0, 64); // backend-generated, per-run (SDK requires it)
                $run['challengeIdemKey'] = $idempotencyKey;
                $run['wait'] = 'challenge';
                $run['calls'] = [self::CALL_SERVICE_BUILD, self::CALL_TWO_FACTOR, self::CALL_CHALLENGE];
                $client = $this->serviceClientFor($id);
                $challenge = $client->twoFactor()->challenge($shareCode, $idempotencyKey, $context);
                $run['challengeId'] = $challenge->challengeId;
                $this->rt->writeRun($runId, $run);
                return Response::json([
                    'runId' => $runId,
                    'action' => ['type' => 'challenge', 'matchingDigits' => $challenge->matchingDigits],
                ]);
        }

        return Response::json(['error' => 'not_found'], 404);
    }

    // ── POST /api/scenarios/{id}/enroll (scenario 8) ───────────────────────────

    /** @param array<string,mixed> $in */
    public function enroll(string $sid, array $in): Response
    {
        $id = (int) $sid;
        if ($id !== 8) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if (!$this->rt->hasConfig($sid)) {
            return Response::json(['error' => 'not_configured'], 409);
        }
        $responseMode = ($in['responseMode'] ?? 'redirect') === 'detached' ? 'detached' : 'redirect';
        $runId = $this->rt->newRunId();

        $oauth = $this->oauthClientFor($id);
        $url = $oauth->authorizeUrl('2fa_enroll', [], $runId, $responseMode);

        $run = [
            'family' => self::FAMILY,
            'scenario' => 8,
            'isEnroll' => true,
            'status' => 'pending',
            'state' => $runId,
            'calls' => [
                $this->idwBuildCall($id),
                $responseMode === 'detached' ? self::CALL_AUTH_ENROLL_DETACHED : self::CALL_AUTH_ENROLL,
            ],
            'wait' => $responseMode === 'detached' ? 'detached_enroll' : 'enroll_redirect',
        ];
        $this->rt->writeRun($runId, $run);

        $action = $responseMode === 'detached'
            ? ['type' => 'detached', 'url' => $url]
            : ['type' => 'redirect', 'url' => $url];
        return Response::json(['runId' => $runId, 'action' => $action]);
    }

    // ── GET /callback ──────────────────────────────────────────────────────────

    /** @param array<string,mixed> $q */
    public function callback(array $q): Response
    {
        $state = (string) ($q['state'] ?? '');
        $run = $this->rt->readRun($state);
        if ($run === null) {
            return Response::redirect('/?error=unknown_run');
        }
        $id = (int) ($run['scenario'] ?? 0);

        try {
            if (($q['enrolled'] ?? '') === 'true') {
                // Redirect-leg enrollment outcome — nothing to exchange; record it.
                $run['status'] = 'done';
                $run['result'] = ['enrolled' => true];
                $run['calls'] = Runtime::addCall($run['calls'] ?? [], self::CALL_ENROLLED_CALLBACK);
            } elseif (isset($q['code']) && $q['code'] !== '') {
                $code = (string) $q['code'];
                if ($id === 5) {
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
        return Response::redirect('/?scenario=' . $id . '&run=' . rawurlencode($state));
    }

    // ── GET /api/runs/{runId} ──────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $run
     */
    public function run(string $runId, array $run): Response
    {
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
        return Response::json($out);
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
                $run['calls'] = Runtime::addCall($run['calls'] ?? [], self::CALL_POLL_SIGNIN);
                $oauth = $this->oauthClientFor($id, self::POLL_TRANSPORT_TIMEOUT_S);
                $body = $oauth->pollResult((string) $run['state'], 2, 2); // loop timeout=2, 2s transport
                $code = (string) ($body['code'] ?? '');
                if ($code !== '') {
                    $run = $this->completeSignin($run, $code);
                }
            } elseif ($wait === 'detached_enroll') {
                $run['calls'] = Runtime::addCall($run['calls'] ?? [], self::CALL_POLL_ENROLL);
                $oauth = $this->oauthClientFor($id, self::POLL_TRANSPORT_TIMEOUT_S);
                $body = $oauth->pollResult((string) $run['state'], 2, 2);
                if (!empty($body['enrolled'])) {
                    $run['status'] = 'done';
                    $run['result'] = ['enrolled' => true];
                }
            } elseif ($wait === 'challenge') {
                $run['calls'] = Runtime::addCall($run['calls'] ?? [], self::CALL_WAIT_RESULT);
                $client = $this->serviceClientFor($id, self::POLL_TRANSPORT_TIMEOUT_S);
                $res = $client->twoFactor()->waitForResult((string) $run['challengeId'], 2, 2);
                $run['status'] = 'done';
                $run['result'] = ['status' => $res->status, 'completed_at' => $res->completedAt];
            }
            // else (redirect / continue-on-phone flows): completion arrives via /callback — stay pending.
        } catch (ApiError $e) {
            // The SDK poll helpers signal a LOGICAL "not completed within {n}s" timeout as ApiError(0)
            // with that exact sentinel message. A real transport failure ALSO surfaces as ApiError(0)
            // (CurlTransport), so the status alone cannot tell them apart — match the SDK's sentinel.
            // Only the logical timeout is "still pending"; a real network/transport failure is a failed
            // run (spec §3), not an eternal pending.
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
        $run['calls'] = Runtime::addCall($run['calls'] ?? [], match ($id) {
            3 => self::CALL_COMPLETE_ONE_TIME,
            4 => self::CALL_COMPLETE_CONNECT,
            default => self::CALL_COMPLETE_SIGNIN,
        });
        $oauth = $this->oauthClientFor($id);
        $out = $oauth->completeSignIn($code, $run['verifier'] ?? null);
        $result = [
            'user' => $out['user'] ?? null,
            'mode' => $out['mode'] ?? null,
            'two_factor' => $out['two_factor'] ?? false,
            'values' => $out['values'] ?? [],
        ];

        if ($id === 4) {
            // Connect: read the person's LIVE values via the service data client.
            $shareCode = (string) ($out['user']['share_code'] ?? '');
            $run['calls'] = Runtime::addCall($run['calls'], self::CALL_SERVICE_BUILD);
            $client = $this->serviceClientFor($id);
            $run['calls'] = Runtime::addCall($run['calls'], self::CALL_CONNECTIONS_LIVE);
            $live = [];
            foreach ($client->connections() as $conn) {
                if ($shareCode !== '' && $conn->shareCode === $shareCode) {
                    $live = $conn->values;
                    break;
                }
            }
            $result['live_values'] = $live;
        }

        $run['status'] = 'done';
        $run['result'] = $result;
        return $run;
    }

    /**
     * Complete an OIDC sign-in (scenario 5) via the third-party OIDC library — id_token verified.
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
        $run['calls'] = Runtime::addCall($run['calls'] ?? [], self::CALL_OIDC_COMPLETE);
        $tokenSet = $authService->callback(
            $oidcClient,
            ['code' => $code, 'state' => (string) $run['state']],
            $this->configRedirectUri($id),
            $session,
        );
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
     * $transportTimeout bounds the HTTP network wait — passed for the short-cycled polls so one blackholed
     * request cannot pin the single worker for the transport's 30s default (spec §3); null keeps the SDK's
     * own default transport for the completion paths.
     */
    private function oauthClientFor(int $id, ?float $transportTimeout = null): OAuthClient
    {
        $path = $this->rt->configPathFor((string) $id);
        $transport = $transportTimeout !== null ? new CurlTransport($transportTimeout) : null;
        if ($this->usesDefaultAuthorizeBase($id)) {
            return OAuthClient::fromConfig($path, $transport); // null → the SDK's default CurlTransport
        }
        $base = (string) ($this->rt->readConfigMeta((string) $id)['authorize_base'] ?? '');
        return new OAuthClient(Config::fromIdwFile($path), $transport ?? new CurlTransport(), authorizeBase: $base);
    }

    /**
     * Whether {@see oauthClientFor()} takes the named-constructor branch. The SAME predicate decides the
     * client AND the trace entry, so the panel can never name a constructor that did not run — the
     * local-stack option really does build the client a different way.
     */
    private function usesDefaultAuthorizeBase(int $id): bool
    {
        $base = (string) ($this->rt->readConfigMeta((string) $id)['authorize_base'] ?? '');
        return $base === '' || $base === OAuthClient::DEFAULT_AUTHORIZE_URL;
    }

    /** The trace entry for the OAuth client {@see oauthClientFor()} just built. */
    private function idwBuildCall(int $id): string
    {
        return $this->usesDefaultAuthorizeBase($id) ? self::CALL_IDW_BUILD : self::CALL_IDW_BUILD_LOCAL;
    }

    /**
     * Build the service data client OFF the scenario's config file (service role). $transportTimeout
     * bounds the HTTP network wait for the short-cycled challenge poll (same reason as above).
     */
    private function serviceClientFor(int $id, ?float $transportTimeout = null): Client
    {
        $path = $this->rt->configPathFor((string) $id);
        if ($transportTimeout === null) {
            return Client::fromConfig($path);
        }
        $cfg = Config::fromFile($path);
        return new Client($cfg, new HttpClient($cfg, new CurlTransport($transportTimeout)));
    }

    /**
     * Build the OIDC client + authorization service (the OIDC compliance surface) from the config file.
     *
     * @return array{0:\Facile\OpenIDClient\Client\ClientInterface,1:\Facile\OpenIDClient\Service\AuthorizationService}
     */
    private function oidcClientFor(int $id): array
    {
        $cfg = $this->rt->readConfig((string) $id);
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
     * The redirect URI recorded in the scenario's config file (used by the OIDC library) — the SAME value
     * the authorize URL carried, so the two legs of the exchange cannot diverge. An absent/empty record
     * re-derives from THIS request's origin; it never substitutes a host.
     */
    private function configRedirectUri(int $id): string
    {
        $stored = (string) ($this->rt->readConfig((string) $id)['oauth_redirect_uri'] ?? '');
        return $stored !== '' ? $stored : $this->redirectUri();
    }

    // ── input / config plumbing ────────────────────────────────────────────────

    /** The origin THIS request arrived on — the only source the redirect URI is ever derived from. */
    private static function requestHost(): string
    {
        return trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    }

    /**
     * The registered redirect URI: http://{host}/callback, host = the origin the browser actually used.
     * Never falls back to a hardcoded host — `127.0.0.1` and `localhost` are DIFFERENT origins for
     * redirect matching and for browser storage alike, so a substituted default drops the developer on an
     * origin whose localStorage never held the setup and whose URI the OAuth app never registered.
     */
    private function redirectUri(): string
    {
        $host = self::requestHost();
        if ($host === '') {
            throw new \RuntimeException(self::NO_ORIGIN);
        }
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
     * A claim carries a mandatory, unique `name` — the key `values` and `attestations` come
     * back under. The demo's config lists claim TYPES, so the type doubles as the name here; a real
     * integration usually names them for its own domain ("billing_email").
     *
     * @param list<string> $types
     * @return list<Claim>
     */
    private function claimObjects(array $types): array
    {
        return array_map(static fn (string $t): Claim => new Claim($t, $t), $types);
    }
}
