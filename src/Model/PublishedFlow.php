<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * The latest published version of a flow — what {@see \Allus\CompanyData\Client::triggerFlowRun()}
 * compiles a run's text-tag values from.
 */
final class PublishedFlow
{
    /**
     * @param array<string,mixed>  $definition        that version's pinned flow graph
     * @param array<string,string> $requestFieldTypes the service's request fields: slug => field type
     */
    public function __construct(
        public readonly int $version,
        public readonly array $definition,
        public readonly array $requestFieldTypes = [],
    ) {
    }

    /** @param array<string,mixed> $obj */
    public static function fromApi(array $obj): self
    {
        $types = [];
        foreach ((is_array($obj['request_field_types'] ?? null) ? $obj['request_field_types'] : []) as $slug => $t) {
            if (is_string($t)) {
                $types[(string) $slug] = $t;
            }
        }

        return new self(
            (int) ($obj['version'] ?? 0),
            is_array($obj['definition'] ?? null) ? $obj['definition'] : [],
            $types,
        );
    }
}
