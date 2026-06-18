<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * A connected person — identity + the slug-keyed value map.
 *
 * NO source field anywhere: {@see $values} is keyed by YOUR request slug.
 */
final class Connection
{
    /**
     * @param array<string,Value> $values keyed by your request slug.
     * @param array<string,mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $personId,
        public readonly ?string $displayName,
        public readonly ?\DateTimeImmutable $connectedAt,
        public readonly array $values = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * Build a Connection from a hardened {@code connectionDetail} (or list) object.
     *
     * {@code connectionDetail} returns {@code {connection_id, user_id, values}}
     * and no display_name/connected_at, so those can be supplied via
     * {@code $identity} (the matching row from the list endpoint, which carries
     * them).
     *
     * @param array<string,mixed> $obj
     * @param callable(string): ?string $typeForSlug
     * @param callable(array<string,mixed>|string): string $decryptValue
     * @param (callable(string): (array<string,mixed>|string))|null $binaryFetch
     * @param array<string,mixed>|null $identity
     */
    public static function fromApi(
        array $obj,
        callable $typeForSlug,
        callable $decryptValue,
        ?callable $binaryFetch = null,
        ?array $identity = null,
    ): self {
        $identity ??= [];
        $connId = $obj['connection_id'] ?? ($obj['id'] ?? ($identity['connection_id'] ?? null));
        $personId = $obj['user_id']
            ?? ($obj['person_id']
            ?? ($obj['person_user_id']
            ?? ($identity['user_id'] ?? null)));
        $displayName = $obj['display_name'] ?? ($identity['display_name'] ?? null);
        $connectedAt = Coerce::dateTime($obj['connected_at'] ?? ($identity['connected_at'] ?? null));

        $values = [];
        $valueMap = $obj['values'] ?? [];
        if (is_array($valueMap)) {
            foreach ($valueMap as $slug => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $values[(string) $slug] = Value::fromApi(
                    $entry,
                    $typeForSlug((string) $slug),
                    $decryptValue,
                    $binaryFetch,
                );
            }
        }

        return new self(
            id: $connId !== null ? (string) $connId : null,
            personId: $personId !== null ? (string) $personId : null,
            displayName: $displayName !== null ? (string) $displayName : null,
            connectedAt: $connectedAt,
            values: $values,
            raw: $obj,
        );
    }
}
