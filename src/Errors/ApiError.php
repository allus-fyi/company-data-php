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
    public function __construct(
        public readonly int $status,
        public readonly ?string $errorKey = null,
        ?string $message = null,
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
