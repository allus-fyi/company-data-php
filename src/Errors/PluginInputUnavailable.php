<?php

declare(strict_types=1);

namespace Allus\CompanyData\Errors;

/**
 * A plugin call could not be made because a REQUIRED input is unavailable.
 *
 * {@see $input} is the plugin's input key, {@see $source} the flow key it is wired to, and
 * {@see $reason} why it is unavailable: `unwired` (no source), `unanswered` (the source has no
 * value yet), `other_party_private` (the source is another party's private value — never sent to a
 * plugin) or `not_convertible` (the value does not convert to the input's declared type). An
 * OPTIONAL input that is unavailable is left out of the call instead.
 */
final class PluginInputUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly string $input,
        public readonly ?string $source,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            "plugin input '%s' is unavailable (%s%s)",
            $input,
            $reason,
            $source !== null && $source !== '' ? ': ' . $source : '',
        ));
    }
}
