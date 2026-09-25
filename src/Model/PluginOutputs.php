<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/** A plugin's outputs for the picks and inputs sent, typed as the plugin declared them. */
final class PluginOutputs
{
    public readonly bool $picksInvalid;

    /**
     * @param array<string,mixed> $outputs
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly array $outputs,
        public readonly array $raw = [],
    ) {
        $this->picksInvalid = false;
    }
}
