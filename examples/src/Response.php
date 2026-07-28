<?php

declare(strict_types=1);

namespace Allus\Examples;

/**
 * A tiny value object a family handler returns instead of emitting HTTP itself. The shared {@see Server}
 * owns the actual output (headers/status/body), so the family handlers stay pure "SDK call → result"
 * code with no HTTP plumbing — the whole point of the shared scaffolding.
 */
final class Response
{
    /** @param array<string,mixed>|null $json */
    private function __construct(
        public readonly int $status,
        public readonly string $kind,          // 'json' | 'text' | 'redirect'
        public readonly ?array $json = null,
        public readonly string $text = '',
        public readonly string $location = '',
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, 'json', json: $data);
    }

    /**
     * The contract's FAILURE envelope (#583): `{"error": "<token> — <reason>", "message": "<reason>"}`.
     *
     * The suite's shared client raises `body.error` VERBATIM and ignores every other key
     * (`api.js` — `throw new Error(body.error || 'start failed (…)')`), so a bare token in `error`
     * reaches the developer as one uninformative word and the REASON — which the backend has right
     * there — is dropped. That is the swallowed failure of standards.html §9: a failure converted into
     * something indistinguishable from any other failure. The token is kept and the reason appended in
     * the shape this contract already uses for exactly this (`no_origin — …`, #574); `message` keeps the
     * bare reason for a programmatic reader.
     *
     * NOT used for the token-only refusals the suite handles by STATUS rather than body — `409
     * not_configured` (`startScenario` maps the 409 before reading the body) and `404 not_found`.
     */
    public static function failure(string $reason, string $token = 'server_error', int $status = 500): self
    {
        $reason = trim($reason);
        return self::json([
            'error' => $token . ' — ' . ($reason !== '' ? $reason : 'no reason was reported'),
            'message' => $reason,
        ], $status);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, 'text', text: $body);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, 'redirect', location: $location);
    }
}
