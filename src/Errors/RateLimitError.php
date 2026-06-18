<?php

declare(strict_types=1);

namespace Allus\CompanyData\Errors;

/**
 * A 429 from a rate-limited endpoint.
 *
 * Subclass of {@see ApiError} with a fixed status of 429; carries the
 * {@see $retryAfter} value parsed from the {@code Retry-After} response header
 * (seconds, or {@code null} when absent).
 *
 * On the changes feed the SDK auto-backs-off + retries within reason; for the
 * heavily-limited connections endpoints it surfaces after backoff so you don't
 * accidentally hammer them.
 */
final class RateLimitError extends ApiError
{
    public function __construct(
        public readonly ?float $retryAfter = null,
        ?string $errorKey = null,
        ?string $message = null,
    ) {
        parent::__construct(429, $errorKey, $message);
    }
}
