<?php

declare(strict_types=1);

namespace Allus\CompanyData\Crypto;

/**
 * One page of a multi-page binary answer (an ID document's front, back, …).
 *
 * {@see $label} is the page's own label ({@code front} | {@code back} |
 * {@code additional}), {@see $name} the original filename the person uploaded it under,
 * {@see $mime} the server-derived media type, and {@see $bytes} the decoded page bytes.
 */
final class BinaryPage
{
    public function __construct(
        public readonly ?string $label,
        public readonly ?string $name,
        public readonly ?string $mime,
        public readonly string $bytes,
    ) {
    }
}
