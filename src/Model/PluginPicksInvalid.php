<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * The plugin answered that the picks no longer fit the current inputs or each other: clear the
 * picks, pick again, and call `pluginOutputs` again before submitting.
 */
final class PluginPicksInvalid
{
    public readonly bool $picksInvalid;

    /** @param array<string,mixed> $raw */
    public function __construct(
        public readonly array $raw = [],
    ) {
        $this->picksInvalid = true;
    }
}
