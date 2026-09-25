<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * A plugin's option list for one block (`[['id' => …, 'label' => …]]`); {@see $more} says the list
 * was cut — narrow the query.
 */
final class PluginOptions
{
    /**
     * @param list<array{id: string, label: string}> $options
     * @param array<string,mixed>                    $raw
     */
    public function __construct(
        public readonly array $options,
        public readonly bool $more,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string,mixed> $reply */
    public static function fromReply(array $reply): self
    {
        $options = [];
        foreach ((is_array($reply['options'] ?? null) ? $reply['options'] : []) as $o) {
            if (is_array($o) && isset($o['id'])) {
                $options[] = ['id' => (string) $o['id'], 'label' => isset($o['label']) && is_scalar($o['label']) ? (string) $o['label'] : ''];
            }
        }

        return new self($options, ($reply['more'] ?? null) === true, $reply);
    }
}
