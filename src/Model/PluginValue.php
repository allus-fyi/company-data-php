<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

use Allus\CompanyData\Errors\ValidationError;

/**
 * A plugin answer — the value of a row whose type key is `plugin`, and of a plugin claim.
 *
 * The answer describes itself: the plugin's name, the field type, the blocks in declared order
 * (`{key, kind, label, id, value}` — a `search_select` pick carries its id and its option label as
 * `value`, a typed block its typed value) and the outputs (`{key, type, label, value}`, typed as
 * the plugin declared them), so reading it never needs the plugin. It is what the answering client
 * submitted — sealed but not signed; a company that must rely on an output checks it with the
 * plugin itself.
 */
final class PluginValue
{
    /**
     * @param list<array{key: ?string, kind: ?string, label: ?string, id: ?string, value: mixed}> $blocks
     * @param list<array{key: ?string, type: ?string, label: ?string, value: mixed}>              $outputs
     * @param array<string,mixed>                                                                 $raw
     */
    public function __construct(
        public readonly ?string $plugin,
        public readonly ?string $type,
        public readonly array $blocks,
        public readonly array $outputs,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Parse a plugin answer's plaintext.
     *
     * @throws ValidationError when the plaintext is not a JSON object with an `outputs` array
     */
    public static function parse(string $plaintext): self
    {
        $shape = json_decode($plaintext);
        if (!$shape instanceof \stdClass || !property_exists($shape, 'outputs') || !is_array($shape->outputs)) {
            throw new ValidationError(null, 'plugin');
        }
        /** @var array<string,mixed> $obj */
        $obj = json_decode($plaintext, true);
        $text = static fn (mixed $v): ?string => $v === null ? null : (is_scalar($v) ? (string) $v : null);
        $blocks = [];
        foreach ((is_array($obj['blocks'] ?? null) ? $obj['blocks'] : []) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $blocks[] = [
                'key' => $text($b['key'] ?? null),
                'kind' => $text($b['kind'] ?? null),
                'label' => $text($b['label'] ?? null),
                'id' => $text($b['id'] ?? null),
                'value' => $b['value'] ?? null,
            ];
        }
        $outputs = [];
        foreach ((is_array($obj['outputs'] ?? null) ? $obj['outputs'] : []) as $o) {
            if (!is_array($o)) {
                continue;
            }
            $outputs[] = [
                'key' => $text($o['key'] ?? null),
                'type' => $text($o['type'] ?? null),
                'label' => $text($o['label'] ?? null),
                'value' => $o['value'] ?? null,
            ];
        }

        return new self($text($obj['plugin'] ?? null), $text($obj['type'] ?? null), $blocks, $outputs, $obj);
    }
}
