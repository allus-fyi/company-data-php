<?php

declare(strict_types=1);

namespace Allus\CompanyData\Http;

use Allus\CompanyData\Config;
use Allus\CompanyData\Errors\ApiError;
use Allus\CompanyData\Errors\AuthError;
use Allus\CompanyData\Errors\RateLimitError;
use Allus\CompanyData\Util\Xml;

/**
 * OAuth token + HTTP layer.
 *
 * The thin transport every higher layer goes through. It owns:
 *
 * - **Auth** — {@code client_credentials} only. On the first call (or when the
 *   cached token is near expiry) it POSTs {@code client_id}/{@code client_secret}
 *   to {@code {api_url}/oauth2/token} and caches the bearer token + its expiry.
 *   Refresh is automatic and transparent; a 401 mid-flight triggers exactly one
 *   refresh-and-retry, then surfaces as {@see AuthError}.
 * - **Region** — the configured {@code api_url} is the starting point AND the
 *   fallback: every response that can name a home base (the token response, a 421
 *   refusal) rebases it, and the token request itself follows the rebase like every
 *   other call — pinning it to the configured value would keep minting at a region a
 *   client's company has left. See {@see rebaseTo}.
 * - **Format** — sets {@code Accept} per {@code config.format}
 *   ({@code application/json} or {@code application/xml}) and parses the body
 *   accordingly (the XML inverse mirrors the platform serializer; XXE-safe).
 * - **Errors** — maps non-2xx to the SDK error taxonomy: a 401 → refresh+retry then
 *   {@see AuthError}; a 429 → read {@code Retry-After} and back off + retry a
 *   bounded number of times, then {@see RateLimitError}; any other non-2xx →
 *   {@see ApiError} carrying the body's {@code error_key} when present.
 *
 * Config-only key handling: the client id/secret come from the
 * {@see Config} — never a method argument.
 */
final class HttpClient
{
    /** Refresh a little before expiry so an in-flight call never races it. */
    private const TOKEN_EXPIRY_SKEW_S = 30.0;

    private const DEFAULT_MAX_RETRIES_429 = 3;
    private const DEFAULT_BACKOFF_S = 1.0;
    private const MAX_BACKOFF_S = 60.0;

    /** The response member (token success body and 421 refusal body alike) naming the home-region base. */
    private const REGION_BASE_MEMBER = 'api_url';
    /** The front door's refusal of a data route: rebase to the named base and replay. */
    private const REBASE_ERROR_KEY = 'region.rebase_required';

    /**
     * The base every request goes to, including the token request. Starts at the configured
     * value; every rebase moves it. Clients do not validate a server-returned base against
     * anything — they store it and use it.
     */
    private string $apiUrl;
    private readonly Transport $transport;
    /** @var callable(float): void */
    private $sleep;
    /** @var callable(): float */
    private $clock;
    private readonly int $maxRetries429;

    private ?string $token = null;
    private float $tokenExpiry = 0.0; // monotonic clock deadline

    /**
     * @param callable(float): void|null $sleep injectable for tests.
     * @param callable(): float|null      $clock injectable monotonic clock for tests.
     */
    public function __construct(
        private readonly Config $config,
        ?Transport $transport = null,
        ?callable $sleep = null,
        ?callable $clock = null,
        int $maxRetries429 = self::DEFAULT_MAX_RETRIES_429,
    ) {
        $this->transport = $transport ?? new CurlTransport();
        $this->sleep = $sleep ?? static function (float $s): void {
            if ($s > 0) {
                usleep((int) round($s * 1_000_000));
            }
        };
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->maxRetries429 = $maxRetries429;
        $this->apiUrl = rtrim($config->apiUrl, '/');
    }

    // ── auth ────────────────────────────────────────────────────────────────

    private function tokenValid(): bool
    {
        return $this->token !== null && ($this->clock)() < $this->tokenExpiry;
    }

    /**
     * POST the client credentials to /oauth2/token and cache the result.
     *
     * Goes to the CURRENT base, exactly like every other call — once a token response has
     * named a home base, subsequent token requests go there too, the same as the data calls
     * they sit beside. The configured value is only the starting point, for the first call of
     * a process and the fallback when nothing has been stored yet.
     */
    private function fetchToken(): string
    {
        $url = "{$this->apiUrl}/oauth2/token";
        $form = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
        ];

        try {
            $resp = $this->transport->post($url, $form, ['Accept' => 'application/json']);
        } catch (ApiError $e) {
            throw new AuthError("token request failed: {$e->getMessage()}", 0, $e);
        }

        $status = $resp->status;
        if ($status < 200 || $status >= 300) {
            [$errorKey, $message] = $this->extractError($resp);
            throw new AuthError(
                "token request rejected (HTTP {$status})"
                . ($errorKey !== null ? " [{$errorKey}]" : '')
                . ($message !== null ? ": {$message}" : '')
            );
        }

        $body = json_decode($resp->body, true);
        if (!is_array($body)) {
            throw new AuthError('token response was not valid JSON');
        }
        $accessToken = $body['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new AuthError('token response missing access_token');
        }

        $expiresIn = isset($body['expires_in']) && is_numeric($body['expires_in'])
            ? (float) $body['expires_in']
            : 3600.0;
        $this->token = $accessToken;
        $this->tokenExpiry = ($this->clock)() + max(0.0, $expiresIn - self::TOKEN_EXPIRY_SKEW_S);
        // The token is minted at the client's home region and only validates there, so the
        // base the response names is where every company-data call must go from here on.
        $this->rebaseTo($body[self::REGION_BASE_MEMBER] ?? null);
        return $this->token;
    }

    // ── region ──────────────────────────────────────────────────────────────

    /**
     * Point subsequent requests — including the next token request — at {@code $candidate}.
     *
     * Returns {@code true} only when the base actually MOVED. A candidate that is absent,
     * not a string, empty, or equal to the current base is not stored and yields
     * {@code false}. Nothing here validates the candidate against a fetched region list: the
     * SDK stores the base the server names and uses it.
     */
    private function rebaseTo(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }
        $base = rtrim(trim($candidate), '/');
        if ($base === '' || $base === $this->apiUrl) {
            return false;
        }
        $this->apiUrl = $base;
        return true;
    }

    private function bearer(bool $forceRefresh = false): string
    {
        if ($forceRefresh || !$this->tokenValid()) {
            return $this->fetchToken();
        }
        \assert($this->token !== null);
        return $this->token;
    }

    // ── requests ──────────────────────────────────────────────────────────

    /**
     * GET {@code $path} → parsed body (assoc array, list, or string).
     *
     * Adds the bearer token + an {@code Accept} header matching
     * {@code config.format}, parses JSON or XML, and maps non-2xx to the SDK
     * errors: 401 → one refresh-and-retry then {@see AuthError}; 429 → bounded
     * Retry-After backoff then {@see RateLimitError}; other non-2xx →
     * {@see ApiError} (carrying the body's {@code error_key} when present).
     *
     * @param array<string,scalar>|null $params
     *
     * @return array<string,mixed>|list<mixed>|string
     */
    public function get(string $path, ?array $params = null): array|string
    {
        return $this->request('GET', $path, $params);
    }

    /**
     * GET returning the RAW 2xx response body bytes — NO JSON/XML parse. For downloading file bytes whose
     * body may be non-JSON (a broadcast document's plaintext) — {@see \Allus\CompanyData\Client::documentFile}.
     * Auth/refresh/retry handling is identical to {@see get}.
     */
    public function getRaw(string $path): string
    {
        /** @var string */
        return $this->request('GET', $path, null, null, null, null, true);
    }

    /**
     * GET returning the whole 2xx {@see Response} — status, headers AND raw body, with no parse.
     *
     * The company-facing binary file endpoints have three 200 shapes (a JSON wrapper for an
     * encrypted answer, a JSON plaintext envelope, raw file bytes) — the bytes shape told apart by
     * {@code Content-Type} and the two JSON ones by the body's {@code encrypted} member — and all
     * three carry an {@code X-Allus-Content-Sha256} digest header. Neither
     * {@see get} (which parses) nor {@see getRaw} (which drops the headers) can express that, so this
     * hands the caller the response itself. Auth/refresh/retry and error mapping are identical.
     */
    public function getResponse(string $path): Response
    {
        /** @var Response */
        return $this->request('GET', $path, null, null, null, null, false, true);
    }

    /**
     * POST {@code $path} with a JSON body or raw bytes → parsed body.
     *
     * @param array<string,mixed>|list<mixed>|null $jsonBody
     *
     * @return array<string,mixed>|list<mixed>|string
     */
    public function post(string $path, ?array $jsonBody = null, ?string $rawBody = null, ?string $contentType = null): array|string
    {
        return $this->request('POST', $path, null, $jsonBody, $rawBody, $contentType);
    }

    /**
     * PUT {@code $path} with a JSON body → parsed body.
     *
     * @param array<string,mixed>|list<mixed>|null $jsonBody
     *
     * @return array<string,mixed>|list<mixed>|string
     */
    public function put(string $path, ?array $jsonBody = null): array|string
    {
        return $this->request('PUT', $path, null, $jsonBody);
    }

    /**
     * DELETE {@code $path} → parsed body.
     *
     * @return array<string,mixed>|list<mixed>|string
     */
    public function delete(string $path): array|string
    {
        return $this->request('DELETE', $path);
    }

    /**
     * The shared request loop for every verb.
     *
     * Adds the bearer token + an {@code Accept} header matching
     * {@code config.format}, carries an optional JSON or raw-bytes body, parses
     * JSON or XML, and maps non-2xx to the SDK errors: 401 → one
     * refresh-and-retry then {@see AuthError}; 429 → bounded Retry-After backoff
     * then {@see RateLimitError}; other non-2xx → {@see ApiError} (carrying the
     * body's {@code error_key} when present).
     *
     * @param array<string,scalar>|null $params
     * @param array<string,mixed>|list<mixed>|null $jsonBody
     *
     * @return array<string,mixed>|list<mixed>|string|Response
     */
    private function request(
        string $method,
        string $path,
        ?array $params = null,
        ?array $jsonBody = null,
        ?string $rawBody = null,
        ?string $contentType = null,
        bool $raw = false,
        bool $wantResponse = false,
    ): array|string|Response {
        $wantsXml = $this->config->format === 'xml';
        $accept = $wantsXml ? 'application/xml' : 'application/json';

        $retries429 = 0;
        $refreshed401 = false;
        $rebased421 = false;
        while (true) {
            // Resolved per attempt, AFTER the bearer call: the first bearer() of a process
            // mints the token and rebases from its response, so the base a fresh $token was
            // just fetched under is the base this request must go to as well.
            $token = $this->bearer(false);
            $url = $this->url($path);
            $headers = [
                'Authorization' => "Bearer {$token}",
                'Accept' => $accept,
            ];

            if ($method === 'GET') {
                $resp = $this->transport->get($url, $params, $headers);
            } else {
                $body = null;
                if ($rawBody !== null) {
                    $body = $rawBody;
                    $headers['Content-Type'] = $contentType ?? 'application/octet-stream';
                } elseif ($jsonBody !== null) {
                    $body = json_encode($jsonBody, JSON_THROW_ON_ERROR);
                    $headers['Content-Type'] = 'application/json';
                }
                $resp = $this->transport->send($method, $url, $params, $body, $headers);
            }

            $status = $resp->status;

            if ($status >= 200 && $status < 300) {
                if ($wantResponse) {
                    return $resp;
                }
                return $raw ? $resp->body : $this->parseBody($resp, $wantsXml);
            }

            if ($status === 401) {
                // One refresh-and-retry, then give up as AuthError.
                if (!$refreshed401) {
                    $refreshed401 = true;
                    $this->bearer(true);
                    continue;
                }
                [$errorKey, $message] = $this->extractError($resp);
                throw new AuthError(
                    'unauthorized after token refresh'
                    . ($errorKey !== null ? " [{$errorKey}]" : '')
                    . ($message !== null ? ": {$message}" : '')
                );
            }

            if ($status === 421) {
                // The front door serves no data route: it names the caller's home base and
                // expects the call there. Rebase once and replay; a second 421 surfaces.
                [$errorKey, $message, $details] = $this->extractError($resp);
                if (
                    !$rebased421
                    && $errorKey === self::REBASE_ERROR_KEY
                    && $this->rebaseTo($details[self::REGION_BASE_MEMBER] ?? null)
                ) {
                    $rebased421 = true;
                    continue;
                }
                throw new ApiError($status, $errorKey, $message, $details);
            }

            if ($status === 429) {
                [$errorKey, $message] = $this->extractError($resp);
                // A pending-cap 429 means the caller already holds the maximum concurrent
                // 2FA challenges — a retry can never clear that, so surface it immediately as an
                // ApiError instead of the blind Retry-After backoff every other 429 gets.
                if ($errorKey === 'twofa.pending_cap') {
                    throw new ApiError($status, $errorKey, $message);
                }
                $retryAfter = $this->parseRetryAfter($resp);
                if ($retries429 < $this->maxRetries429) {
                    $retries429++;
                    ($this->sleep)($this->backoffDelay($retryAfter, $retries429));
                    continue;
                }
                throw new RateLimitError($retryAfter, $errorKey, $message);
            }

            // Any other non-2xx → ApiError with the body's error_key.
            [$errorKey, $message, $details] = $this->extractError($resp);
            throw new ApiError($status, $errorKey, $message, $details);
        }
    }

    /**
     * Resolve {@code $path} against the CURRENT base. An already-absolute {@code $path} (the
     * lazy binary handle's server-supplied {@code value_url}) is reduced to its path+query and
     * rebuilt against the current base too — so a value_url minted before a rebase, or replayed
     * on a 421 retry after one, still lands at the base every other request now uses.
     */
    private function url(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $path = self::pathAndQuery($path);
        }
        return $this->apiUrl . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    /** The path + query (+ fragment) portion of an absolute URL, dropping its scheme and host. */
    private static function pathAndQuery(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $result = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $result .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $result .= '#' . $parts['fragment'];
        }
        return $result;
    }

    /**
     * @return array<string,mixed>|list<mixed>|string
     */
    public function parseBody(Response $resp, bool $wantsXml): array|string
    {
        $text = $resp->body;
        if (trim($text) === '') {
            return [];
        }
        if ($wantsXml) {
            try {
                return Xml::parse($text);
            } catch (\RuntimeException $e) {
                throw new ApiError($resp->status, null, $e->getMessage());
            }
        }
        $body = json_decode($text, true);
        if ($body === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new ApiError($resp->status, null, 'response was not valid JSON: ' . json_last_error_msg());
        }
        return $body;
    }

    /** The binary file fetch parses the structured shape itself, and must do it the way the
     *  rest of this client does — an XML-configured client would otherwise silently lose XML support
     *  on exactly one endpoint. */
    public function wantsXml(): bool
    {
        return $this->config->format === 'xml';
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Pull {@code error_key} + a message out of a non-2xx body (JSON or XML).
     *
     * @return array{0: ?string, 1: ?string, 2: array<string,mixed>}
     */
    private function extractError(Response $resp): array
    {
        $body = json_decode($resp->body, true);
        if (!is_array($body)) {
            // Maybe an XML error envelope; try a best-effort parse, else fall back.
            try {
                $body = Xml::parse($resp->body);
            } catch (\Throwable) {
                // Three elements on EVERY path: the caller destructures three, and a two-element
                // return here would be an undefined-key warning plus a TypeError into ApiError's
                // typed `array $details` — on the error path, where it is least likely to be noticed.
                return [null, $resp->body !== '' ? $resp->body : null, []];
            }
        }
        if (is_array($body) && !array_is_list($body)) {
            $errorKey = $body['error_key'] ?? null;
            $message = $body['error'] ?? ($body['message'] ?? null);
            // Everything BESIDE the key and the message travels on as `details`, so a body that
            // carries actionable data (a 410 file_expired's content_sha256 + expired_at) is readable
            // without a bespoke exception type per response.
            $details = $body;
            unset($details['error_key'], $details['error'], $details['message']);
            return [
                $errorKey !== null ? (string) $errorKey : null,
                $message !== null ? (string) $message : null,
                $details,
            ];
        }
        return [null, null, []];
    }

    /** Parse the Retry-After header (delta-seconds form) → float seconds. */
    private function parseRetryAfter(Response $resp): ?float
    {
        $raw = $resp->header('Retry-After');
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if (!is_numeric($raw)) {
            // An HTTP-date Retry-After is allowed by spec but the platform sends
            // delta-seconds; if we ever get a date, fall back to default backoff.
            return null;
        }
        return (float) $raw;
    }

    /** Sleep duration before the next 429 retry. */
    private function backoffDelay(?float $retryAfter, int $attempt): float
    {
        if ($retryAfter !== null && $retryAfter >= 0) {
            return min($retryAfter, self::MAX_BACKOFF_S);
        }
        return min(self::DEFAULT_BACKOFF_S * (2 ** ($attempt - 1)), self::MAX_BACKOFF_S);
    }
}
