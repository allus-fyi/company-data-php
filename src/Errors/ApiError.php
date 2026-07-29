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
