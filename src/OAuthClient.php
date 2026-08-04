<?php

declare(strict_types=1);

namespace Allus\CompanyData;

use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Errors\ApiError;
use Allus\CompanyData\Errors\AuthError;
use Allus\CompanyData\Errors\ConfigError;
use Allus\CompanyData\Http\CurlTransport;
use Allus\CompanyData\Http\Response;
use Allus\CompanyData\Http\Transport;

/**
 * "Sign in with allme" — the RP-side OAuth client.
 *
 * A third-party site embeds a "Sign in with allme" button, sends the person to the hosted consent
 * screen, and — once they approve — receives an authorization code at its redirect URI. This wraps
 * the RP half: build the button URL, exchange the code, read the identity, and (for one_time)
 * decrypt the shared values. Config-only key handling still holds — the app private key + passphrase
 * come from {@see Config} (the idw role), never a method argument.
 */
final class OAuthClient
{
    /** The hosted consent surface. Native apps claim this https link; web is the fallback. */
    public const DEFAULT_AUTHORIZE_URL = 'https://web.allme.fyi/auth';

    private const NON_CLAIMABLE = ['photo', 'document', 'legal_document'];
    private const MAX_CLAIMS = 15;
    private const MODES = ['signin', 'one_time', 'connect', '2fa_enroll'];
    private const RESPONSE_MODES = ['redirect', 'detached'];

    private readonly string $apiUrl;

    /** @param callable(int):void $sleep sleeper (seconds); tests inject a no-op. */
    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport = new CurlTransport(),
        private readonly string $authorizeBase = self::DEFAULT_AUTHORIZE_URL,
        private $sleep = null,
    ) {
        if (($config->oauthClientId ?? '') === '' || ($config->oauthRedirectUri ?? '') === '') {
            throw new ConfigError('OAuthClient requires oauth_client_id + oauth_redirect_uri (idw role)');
        }
        $this->apiUrl = rtrim($config->apiUrl, '/');
        if ($this->sleep === null) {
            $this->sleep = static fn (int $s) => $s > 0 ? sleep($s) : null;
        }
    }

    /** Build from an idw-role JSON config file. */
    public static function fromConfig(string $path, ?Transport $transport = null): self
    {
        return new self(Config::fromIdwFile($path), $transport ?? new CurlTransport());
    }

    /** Build from ALLUS_OAUTH_* env vars. */
    public static function fromEnv(?Transport $transport = null): self
    {
        return new self(Config::fromIdwEnv(), $transport ?? new CurlTransport());
    }

    /**
     * Build the consent-screen URL — the "Sign in with allme" button target.
     *
     * @param list<Claim> $claims one_time claims (validated: binary/unknown dropped, cap 15)
     */
    public function authorizeUrl(
        string $mode,
        array $claims = [],
        ?string $state = null,
        string $responseMode = 'redirect',
        ?string $codeChallenge = null,
        ?string $redirectUri = null,
    ): string {
        if (!in_array($mode, self::MODES, true)) {
            throw new ConfigError("invalid mode '{$mode}' (expected signin | one_time | connect | 2fa_enroll)");
        }
        if (!in_array($responseMode, self::RESPONSE_MODES, true)) {
            throw new ConfigError("invalid responseMode '{$responseMode}' (expected redirect | detached)");
        }
        $params = [
            'client_id' => (string) $this->config->oauthClientId,
            'redirect_uri' => $redirectUri ?? (string) $this->config->oauthRedirectUri,
            'mode' => $mode,
            'response_mode' => $responseMode,
        ];
        if ($state !== null) {
            $params['state'] = $state;
        }
        if ($codeChallenge !== null && $codeChallenge !== '') {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }
        $cleaned = $this->cleanClaims($claims);
        if ($cleaned !== []) {
            $params['claims'] = json_encode($cleaned, JSON_UNESCAPED_SLASHES);
        }

        return $this->authorizeBase . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param list<Claim> $claims
     * @return list<array<string,mixed>>
     */
    private function cleanClaims(array $claims): array
    {
        $out = [];
        $seen = [];
        foreach ($claims as $c) {
            if ($c->type === '' || in_array($c->type, self::NON_CLAIMABLE, true)) {
                continue;
            }
            // §2: `name` is the claim's identity and it is mandatory. Refused HERE rather than
            // left to the API, so the integration error surfaces at the call that made it.
            $name = trim($c->name);
            if ($name === '') {
                throw new ConfigError('every claim must carry a `name`');
            }
            if (isset($seen[$name])) {
                throw new ConfigError("duplicate claim name '{$name}'");
            }
            $seen[$name] = true;
            $entry = ['name' => $name, 'type' => $c->type];
            if ($c->suggest !== null && $c->suggest !== '') {
                $entry['suggest'] = $c->suggest;
            }
            if ($c->required) {
                $entry['required'] = true;
            }
            if ($c->verified) {
                $entry['verified'] = true;
            }
            if ($c->label !== null && $c->label !== '') {
                $entry['label'] = $c->label;
            }
            $out[] = $entry;
            if (count($out) >= self::MAX_CLAIMS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Swap the authorization code for a token (POST /oauth2/token).
     *
     * @return array<string,mixed>
     */
    public function exchangeCode(string $code, ?string $codeVerifier = null): array
    {
        $form = [
            'grant_type' => 'authorization_code',
            'client_id' => (string) $this->config->oauthClientId,
            'code' => $code,
            'redirect_uri' => (string) $this->config->oauthRedirectUri,
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $form['code_verifier'] = $codeVerifier;
        }
        if (($this->config->oauthClientSecret ?? '') !== '') {
            $form['client_secret'] = (string) $this->config->oauthClientSecret;
        }
        $res = $this->transport->post("{$this->apiUrl}/oauth2/token", $form, ['Accept' => 'application/json']);

        return $this->parse($res, 'token exchange');
    }

    /**
     * Read the signed-in identity (GET /api/oauth/userinfo) with the RP token.
     *
     * @return array<string,mixed>
     */
    public function userinfo(string $accessToken): array
    {
        $res = $this->transport->get("{$this->apiUrl}/api/oauth/userinfo", null, [
            'Authorization' => "Bearer {$accessToken}",
            'Accept' => 'application/json',
        ]);

        return $this->parse($res, 'userinfo');
    }

    /**
     * Exchange + userinfo in one call, decrypting one_time values via the configured app key.
     *
     * §5: `user['sub']` IS the person's SHARE CODE and is byte-identical to the id_token's
     * `sub`; `share_code` is retained beside it for compatibility and now simply equals it.
     * `display_name` is GONE — it is a consented `name` claim now, or nothing: ask for
     * `new Claim(name: 'name', type: 'text')` and read `$result['values']['name']`.
     *
     * §3.1a: `attestations` is an ADDITIVE sibling map, keyed by the SAME claim name as
     * `values`, present only for a `verified` claim under ENCRYPTED delivery. An integration that
     * never reads it behaves exactly as before. Each entry is
     * `{verified: bool, hash: string, salt: string, verifiedAt: string}` — `verified` is recomputed
     * BY THIS SDK in constant time over the plaintext it just decrypted, never passed through from
     * the server. **A `verified === false` entry means MISMATCH and you MUST reject the value.** A
     * claim ABSENT from `attestations` means "not attested" — never "wrong" — and must be treated as
     * unverified. `verifiedAt` attests the value as verified AT THAT MOMENT, not verified today.
     *
     * `values_cipher` is an ADDITIVE sibling of `values`, keyed by the same claim name: the RAW
     * app-key ciphertext wrapper `values` was decrypted from, exactly as delivered by userinfo. It
     * lets a caller show — to itself, a log, or a person auditing the integration — that a claim's
     * plaintext really did come from encrypted delivery rather than being trusted verbatim. Absent or
     * empty for a claim/mode that carries no ciphertext (signin mode, or plaintext delivery, where
     * there is honestly nothing to show); never a placeholder standing in for "none returned".
     *
     * @return array{user:array<string,?string>,mode:?string,two_factor:bool,values:array<string,string>,values_cipher:array<string,mixed>,attestations:array<string,array{verified:bool,hash:string,salt:string,verifiedAt:string}>}
     */
    public function completeSignIn(string $code, ?string $codeVerifier = null): array
    {
        $token = $this->exchangeCode($code, $codeVerifier);
        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new AuthError('token exchange returned no access_token');
        }

        return $this->resolveUserinfo($accessToken, self::str($token, 'mode'));
    }

    /**
     * Read + decrypt userinfo for an access token ALREADY held — the second half of
     * {@see completeSignIn()}, split out so a caller that obtained its access token through its
     * own separate exchange can still resolve and decrypt the claim values. Config-only key
     * handling still holds — the caller passes no key/passphrase, only the token it already has;
     * the private key is read from {@see Config} exactly as {@see completeSignIn()} does.
     *
     * Re-exchanging the code here would be wrong (a second exchange either mints a second grant or
     * fails outright), so this method never does the exchange — only the read + decrypt.
     *
     * @return array{user:array<string,?string>,mode:?string,two_factor:bool,values:array<string,string>,values_cipher:array<string,mixed>,attestations:array<string,array{verified:bool,hash:string,salt:string,verifiedAt:string}>}
     */
    public function resolveUserinfo(string $accessToken, ?string $fallbackMode = null): array
    {
        $info = $this->userinfo($accessToken);
        $values = [];
        $valuesCipher = [];
        $attestations = [];
        $raw = $info['values'] ?? null;
        if (is_array($raw) && $raw !== []) {
            $values = $this->decryptValues($raw);
            $valuesCipher = $raw;
            $rawAttest = $info['values_attestation'] ?? null;
            if (is_array($rawAttest) && $rawAttest !== []) {
                $attestations = $this->decryptAttestations($rawAttest, $values);
            }
        }

        return [
            'user' => [
                'sub' => self::str($info, 'sub'),
                'share_code' => self::str($info, 'share_code'),
            ],
            'mode' => self::str($info, 'mode') ?? $fallbackMode,
            'two_factor' => (bool) ($info['two_factor'] ?? false),
            'values' => $values,
            'values_cipher' => $valuesCipher,
            'attestations' => $attestations,
        ];
    }

    /**
     * §3.1a — open the app-key-sealed attestations and attest each value ourselves.
     *
     * A SECOND decrypt per verified claim: `values` is byte-identical to before, but each attestation
     * is its own `{"_enc":1,...}` object. A passthrough accessor handing back an undecrypted blob
     * would not be an implementation of this.
     *
     * An attestation that cannot be opened or parsed is DROPPED, not surfaced as `verified: false` —
     * absence means "not attested" and a mismatch means "reject the value", and conflating the two
     * would turn a key or transport problem into an accusation that the data was tampered with.
     *
     * @param array<string,mixed> $raw
     * @param array<string,string> $values
     * @return array<string,array{verified:bool,hash:string,salt:string,verifiedAt:string}>
     */
    private function decryptAttestations(array $raw, array $values): array
    {
        // decryptValues() ran first and already refused an unconfigured key, so reaching here with
        // one is impossible; re-reading rather than threading the key keeps the two paths independent.
        $pem = @file_get_contents((string) $this->config->oauthPrivateKey);
        if ($pem === false) {
            return [];
        }
        $key = Crypto::loadPrivateKey($pem, (string) $this->config->oauthKeyPassphrase);
        $out = [];
        foreach ($raw as $slug => $wrapper) {
            $slug = (string) $slug;
            if (!isset($values[$slug]) || (!is_array($wrapper) && !is_string($wrapper))) {
                continue;
            }
            try {
                $decoded = json_decode(Crypto::decrypt($wrapper, $key), true);
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($decoded)) {
                continue;
            }
            $hash = (string) ($decoded['hash'] ?? '');
            $salt = (string) ($decoded['salt'] ?? '');
            if ($hash === '' || $salt === '') {
                continue;
            }
            $out[$slug] = [
                // Recomputed here, constant-time, over the plaintext just decrypted — never trusted
                // from the server. false = the delivered value is NOT the verified one; reject it.
                'verified' => Crypto::hashMatches($salt, $hash, $values[$slug]),
                'hash' => $hash,
                'salt' => $salt,
                'verifiedAt' => (string) ($decoded['verified_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,string>
     */
    private function decryptValues(array $raw): array
    {
        if (($this->config->oauthPrivateKey ?? '') === '' || ($this->config->oauthKeyPassphrase ?? '') === '') {
            throw new ConfigError('one_time values present but oauth_private_key / oauth_key_passphrase not configured');
        }
        $pem = @file_get_contents((string) $this->config->oauthPrivateKey);
        if ($pem === false) {
            throw new ConfigError('could not read oauth_private_key');
        }
        $key = Crypto::loadPrivateKey($pem, (string) $this->config->oauthKeyPassphrase);
        $out = [];
        foreach ($raw as $slug => $wrapper) {
            /** @var array<string,mixed>|string $wrapper */
            $out[(string) $slug] = Crypto::decrypt($wrapper, $key);
        }

        return $out;
    }

    /**
     * Poll /oauth2/result for a detached sign-in or enrollment (single-delivery).
     *
     * A detached sign-in delivers `{code, state}`; a detached `2fa_enroll` delivers
     * `{enrolled: true, state}`. Returns on the first delivered shape (`code` OR `enrolled`)
     * and never polls past it, so a one-shot enrollment result is not consumed and lost.
     *
     * @return array<string,mixed>
     */
    public function pollResult(string $state, int $timeout = 600, int $interval = 2): array
    {
        $form = ['client_id' => (string) $this->config->oauthClientId, 'state' => $state];
        if (($this->config->oauthClientSecret ?? '') !== '') {
            $form['client_secret'] = (string) $this->config->oauthClientSecret;
        }
        $deadline = time() + max(1, $timeout);
        while (true) {
            $res = $this->transport->post("{$this->apiUrl}/oauth2/result", $form, ['Accept' => 'application/json']);
            if ($res->status === 200) {
                $body = self::decodeObject($res->body);
                // Return on the first delivered terminal shape — a sign-in `code` OR a
                // 2fa_enroll `enrolled` sentinel ({enrolled: true, state}). Both are one-shot;
                // returning here (rather than looping) keeps an enrollment result from being
                // consumed and lost to a timeout.
                if (isset($body['code']) || !empty($body['enrolled'])) {
                    return $body;
                }
            } elseif ($res->status === 410) {
                throw new ApiError(410, 'oauth.result_expired', 'detached sign-in expired before completion');
            } elseif ($res->status !== 202) {
                [$key, $msg] = self::err($res->body);
                throw new ApiError($res->status, $key, $msg ?? "result poll rejected (HTTP {$res->status})");
            }
            if (time() >= $deadline) {
                throw new ApiError(0, null, "detached sign-in not completed within {$timeout}s");
            }
            ($this->sleep)($interval);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function parse(Response $res, string $what): array
    {
        if ($res->status >= 200 && $res->status < 300) {
            return self::decodeObject($res->body);
        }
        [$key, $msg] = self::err($res->body);
        if ($res->status === 401 || $res->status === 403) {
            throw new AuthError(
                "{$what} rejected (HTTP {$res->status})"
                . ($key !== null ? " [{$key}]" : '')
                . ($msg !== null ? ": {$msg}" : '')
            );
        }
        throw new ApiError($res->status, $key, $msg ?? "{$what} rejected (HTTP {$res->status})");
    }

    /** @return array<string,mixed> */
    private static function decodeObject(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{0:?string,1:?string} */
    private static function err(string $body): array
    {
        $m = self::decodeObject($body);

        return [self::str($m, 'error_key'), self::str($m, 'error')];
    }

    /** @param array<string,mixed> $m */
    private static function str(array $m, string $key): ?string
    {
        $v = $m[$key] ?? null;

        return is_string($v) ? $v : null;
    }
}
