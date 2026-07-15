<?php

declare(strict_types=1);

namespace Allus\CompanyData;

/**
 * A one_time claim the RP asks for in "Sign in with allme" (#195): a field TYPE + an
 * advisory suggestion.
 */
final class Claim
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $suggest = null,
        public readonly bool $required = false,
        public readonly ?string $label = null,
    ) {
    }
}
