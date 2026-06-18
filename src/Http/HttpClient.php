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

    private readonly string $apiUrl;
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

    /** POST the client credentials to /oauth2/token and cache the result. */
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
        return $this->token;
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
        $url = $this->url($path);
        $wantsXml = $this->config->format === 'xml';
        $accept = $wantsXml ? 'application/xml' : 'application/json';

        $retries429 = 0;
        $refreshed401 = false;
        while (true) {
            $token = $this->bearer(false);
            $resp = $this->transport->get($url, $params, [
                'Authorization' => "Bearer {$token}",
                'Accept' => $accept,
            ]);

            $status = $resp->status;

            if ($status >= 200 && $status < 300) {
                return $this->parseBody($resp, $wantsXml);
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

            if ($status === 429) {
                $retryAfter = $this->parseRetryAfter($resp);
                if ($retries429 < $this->maxRetries429) {
                    $retries429++;
                    ($this->sleep)($this->backoffDelay($retryAfter, $retries429));
                    continue;
                }
                [$errorKey, $message] = $this->extractError($resp);
                throw new RateLimitError($retryAfter, $errorKey, $message);
            }

            // Any other non-2xx → ApiError with the body's error_key.
            [$errorKey, $message] = $this->extractError($resp);
            throw new ApiError($status, $errorKey, $message);
        }
    }

    private function url(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return $this->apiUrl . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    /**
     * @return array<string,mixed>|list<mixed>|string
     */
    private function parseBody(Response $resp, bool $wantsXml): array|string
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

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Pull {@code error_key} + a message out of a non-2xx body (JSON or XML).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function extractError(Response $resp): array
    {
        $body = json_decode($resp->body, true);
        if (!is_array($body)) {
            // Maybe an XML error envelope; try a best-effort parse, else fall back.
            try {
                $body = Xml::parse($resp->body);
            } catch (\Throwable) {
                return [null, $resp->body !== '' ? $resp->body : null];
            }
        }
        if (is_array($body) && !array_is_list($body)) {
            $errorKey = $body['error_key'] ?? null;
            $message = $body['error'] ?? ($body['message'] ?? null);
            return [
                $errorKey !== null ? (string) $errorKey : null,
                $message !== null ? (string) $message : null,
            ];
        }
        return [null, null];
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
