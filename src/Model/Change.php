<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

use Allus\CompanyData\Crypto\BinaryHandle;

/**
 * A change feed / webhook event.
 *
 * {@see $id} is the stable server change-row id (the pump dedupes on it after a
 * crash/replay); {@see $at} is the change time (there is NO separate
 * updatedAt on a change). {@see $slug}/{@see $value}/{@see $live} are present only
 * on {@code field_updated} (connection/consent events carry no slot/value).
 */
final class Change
{
    /**
     * @param string|array<string,mixed>|\DateTimeImmutable|BinaryHandle|null $value
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $event,
        public readonly ?string $personId,
        public readonly ?string $slug = null,
        public readonly string|array|\DateTimeImmutable|BinaryHandle|null $value = null,
        public readonly ?bool $live = null,
        public readonly ?\DateTimeImmutable $at = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Build a Change from one hardened changes-feed / webhook event object.
     *
     * @param array<string,mixed> $obj
     * @param callable(string): ?string $typeForSlug
     * @param callable(array<string,mixed>|string): string $decryptValue
     * @param (callable(string): (array<string,mixed>|string))|null $binaryFetch
     */
    public static function fromApi(
        array $obj,
        callable $typeForSlug,
        callable $decryptValue,
        ?callable $binaryFetch = null,
    ): self {
        $slug = isset($obj['slug']) ? (string) $obj['slug'] : null;
        $event = isset($obj['event']) ? (string) $obj['event'] : null;
        $live = array_key_exists('live', $obj) ? Coerce::bool($obj['live']) : null;

        $value = null;
        if ($event === 'field_updated' && $slug !== null) {
            // Reuse the Value typing path so feed + connection produce identical
            // typed values (incl. the same lazy BinaryHandle for binaries).
            if (array_key_exists('value', $obj) || array_key_exists('value_url', $obj)) {
                $value = ValueTyping::typed($obj, $typeForSlug($slug), $decryptValue, $binaryFetch);
            }
        }

        $personId = $obj['person_user_id'] ?? ($obj['person_id'] ?? null);

        return new self(
            id: isset($obj['id']) ? (string) $obj['id'] : null,
            event: $event,
            personId: $personId !== null ? (string) $personId : null,
            slug: $slug,
            value: $value,
            live: $live,
            at: Coerce::dateTime($obj['at'] ?? null),
            raw: $obj,
        );
    }

    /**
     * Parse the {@code /changes} response → a list of typed Change events.
     *
     * @param array<string,mixed>|list<mixed> $body
     * @param callable(string): ?string $typeForSlug
     * @param callable(array<string,mixed>|string): string $decryptValue
     * @param (callable(string): (array<string,mixed>|string))|null $binaryFetch
     *
     * @return list<self>
     */
    public static function listFromApi(
        array $body,
        callable $typeForSlug,
        callable $decryptValue,
        ?callable $binaryFetch = null,
    ): array {
        if (array_is_list($body)) {
            $items = $body;
        } else {
            $items = $body['changes'] ?? [];
        }
        $out = [];
        foreach ($items as $o) {
            if (is_array($o)) {
                $out[] = self::fromApi($o, $typeForSlug, $decryptValue, $binaryFetch);
            }
        }
        return $out;
    }
}
