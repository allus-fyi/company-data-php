<?php

declare(strict_types=1);

namespace Allus\CompanyData\Errors;

/**
 * A submitted value failed field-type validation (#302) before encryption.
 *
 * Carries the offending field {@see $slug} and its {@see $fieldType} so the caller
 * can point at the bad answer without shipping malformed ciphertext.
 */
final class ValidationError extends \RuntimeException
{
    public function __construct(
        public readonly ?string $slug,
        public readonly ?string $fieldType,
    ) {
        parent::__construct(sprintf("invalid %s value for '%s'", (string) $fieldType, $slug ?? 'value'));
    }
}
