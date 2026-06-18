<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * A request-field DEFINITION — YOUR config, never the person's.
 *
 * {@see $mandatory} folds the API's two flags: it is true when the field is
 * mandatory to provide OR mandatory to stay connected.
 */
final class RequestField
{
    /**
     * @param array<string,mixed> $raw the underlying hardened API dict (debugging).
     */
    public function __construct(
        public readonly ?string $slug,
        public readonly ?string $label,
        public readonly ?string $type,
        public readonly bool $oneTime,
        public readonly bool $mandatory,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string,mixed> $obj
     */
    public static function fromApi(array $obj): self
    {
        return new self(
            slug: isset($obj['slug']) ? (string) $obj['slug'] : null,
            label: isset($obj['label']) ? (string) $obj['label'] : null,
            type: isset($obj['type']) ? (string) $obj['type'] : null,
            oneTime: (bool) Coerce::bool($obj['one_time'] ?? null),
            mandatory: (bool) (
                Coerce::bool($obj['mandatory_provide'] ?? null)
                || Coerce::bool($obj['mandatory_connected'] ?? null)
            ),
            raw: $obj,
        );
    }

    /**
     * Parse the {@code /request-fields} response → a list of definitions.
     *
     * @param array<string,mixed>|list<mixed> $body
     *
     * @return list<self>
     */
    public static function listFromApi(array $body): array
    {
        if (array_is_list($body)) {
            $items = $body;
        } else {
            $items = $body['request_fields'] ?? [];
        }
        $out = [];
        foreach ($items as $o) {
            if (is_array($o)) {
                $out[] = self::fromApi($o);
            }
        }
        return $out;
    }
}
