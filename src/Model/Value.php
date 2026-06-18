<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

use Allus\CompanyData\Crypto\BinaryHandle;

/**
 * A single answer for one of YOUR request slots.
 *
 * {@see $value} is the typed plaintext (string / array / DateTimeImmutable / lazy
 * {@see BinaryHandle}); {@see $live} = the person chose "keep connected"
 * (auto-updates) vs a one-time snapshot; {@see $updatedAt} = when this answer last
 * changed. Both ride on the Value (per-answer), not the definition.
 */
final class Value
{
    /**
     * @param string|array<string,mixed>|\DateTimeImmutable|BinaryHandle|null $value
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string|array|\DateTimeImmutable|BinaryHandle|null $value,
        public readonly bool $live,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Build a typed Value from one hardened {value|value_url, live, updatedAt} entry.
     *
     * @param array<string,mixed> $obj
     * @param callable(array<string,mixed>|string): string $decryptValue
     * @param (callable(string): (array<string,mixed>|string))|null $binaryFetch
     */
    public static function fromApi(
        array $obj,
        ?string $fieldType,
        callable $decryptValue,
        ?callable $binaryFetch = null,
    ): self {
        $live = (bool) Coerce::bool($obj['live'] ?? null);
        $updatedAt = Coerce::dateTime($obj['updatedAt'] ?? ($obj['updated_at'] ?? null));
        $typed = ValueTyping::typed($obj, $fieldType, $decryptValue, $binaryFetch);
        return new self(value: $typed, live: $live, updatedAt: $updatedAt, raw: $obj);
    }
}
