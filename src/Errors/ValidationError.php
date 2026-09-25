<?php

declare(strict_types=1);

namespace Allus\CompanyData\Errors;

/**
 * A submitted value failed field-type validation before encryption.
 *
 * Carries the offending field {@see $slug} and its {@see $fieldType} so the caller
 * can point at the bad answer without shipping malformed ciphertext.
 *
 * {@see $bound} / {@see $boundValue} are set when a flow field's minimum or maximum refused the
 * value: which bound (`min` | `max`) and the bound's value as the field's expression computed it.
 * Both are null on a type failure.
 */
final class ValidationError extends \RuntimeException
{
    public function __construct(
        public readonly ?string $slug,
        public readonly ?string $fieldType,
        public readonly ?string $bound = null,
        public readonly mixed $boundValue = null,
    ) {
        parent::__construct($bound === null
            ? sprintf("invalid %s value for '%s'", (string) $fieldType, $slug ?? 'value')
            : sprintf(
                "value for '%s' is %s %s",
                $slug ?? 'value',
                $bound === 'min' ? 'below its minimum' : 'above its maximum',
                is_scalar($boundValue) ? (string) $boundValue : '',
            ));
    }
}
