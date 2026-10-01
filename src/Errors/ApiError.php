<?php

declare(strict_types=1);

namespace Allus\CompanyData\Errors;

/**
 * Any non-2xx from the API.
 *
 * Carries the HTTP {@see $status}, the platform {@see $errorKey} (when the body
 * provided one), and a human-readable message. A transport failure (no HTTP
 * response) surfaces as {@code ApiError(0, null, ...)}.
 *
 * A 503 {@code db.writes_paused} means saving is paused (the platform cannot
 * complete a save in every region). Nothing was written, so the call is safe to
 * repeat; the response's {@code Retry-After} is 30 seconds. Any call that is not a
 * GET, the change-feed drains and {@see \Allus\CompanyData\OAuthClient::pollResult()}
 * can throw it; the token request cannot. The SDK does not retry it.
 *
 * A 503 {@code platform.out_of_order} means the region serving the call is being
 * rebuilt. The request was not processed, so the call is safe to repeat; the
 * response's {@code Retry-After} is 300 seconds. Any call can throw it, reads and
 * the change-feed drains included, except the {@code client_credentials} token
 * request. The SDK does not retry it.
 *
 * Not {@code final}: {@see RateLimitError} extends it (a 429 IS an ApiError).
 */
class ApiError extends \RuntimeException
{
    /**
     * @param array<string,mixed> $details the error body's remaining fields, verbatim.
     *
     * Some responses carry actionable data BESIDE the key: a 410
     * {@code company_data.file_expired} returns the expired answer's {@code content_sha256} and
     * {@code expired_at}, so a consumer can record that its archived copy is now the only one and
     * still prove what it holds. Generic rather than a bespoke subclass — every error body's extra
     * fields become reachable, and no future one needs a new exception type to be readable.
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $errorKey = null,
        ?string $message = null,
        public readonly array $details = [],
    ) {
        $parts = ["HTTP {$status}"];
        if ($errorKey !== null && $errorKey !== '') {
            $parts[] = "({$errorKey})";
        }
        if ($message !== null && $message !== '') {
            $parts[] = ": {$message}";
        }
        parent::__construct(implode(' ', $parts));
    }
}
