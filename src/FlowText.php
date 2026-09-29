<?php

declare(strict_types=1);

namespace Allus\CompanyData;

/**
 * The value tags a contract-flow TEXT element names, read by the platform's text grammar: HTML tags
 * are removed first, `\[` `\]` `\{` `\\` are escapes, and a `{{…}}` an escape breaks is not a tag. A
 * tag inside a link address (`[a href=X]`) is a tag too. A starter compiles the values of the
 * definition's non-owner PARTY tags before it starts a run ({@see Client::triggerFlowRun()}).
 */
final class FlowText
{
    private const HTML_TAG = '/<\/?[a-zA-Z][^<>]*>/';
    private const TAG_AT = '/\G\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){0,2})\s*\}\}/i';
    private const ESCAPABLE = '[]{\\';

    /**
     * Every value-tag key a text body names — in its text and its link addresses — lower-cased,
     * first use first.
     *
     * @return list<string>
     */
    public static function tags(string $body): array
    {
        $s = (string) preg_replace(self::HTML_TAG, '', $body);
        $out = [];
        $len = strlen($s);
        $inAddress = false;
        $i = 0;
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len && str_contains(self::ESCAPABLE, $s[$i + 1])) {
                $i += 2;
                continue;
            }
            if ($c === '{' && preg_match(self::TAG_AT, $s, $m, 0, $i) === 1) {
                $k = strtolower($m[1]);
                if (!in_array($k, $out, true)) {
                    $out[] = $k;
                }
                $i += strlen($m[0]);
                continue;
            }
            if ($inAddress && $c === ']') {
                $inAddress = false;
                $i++;
                continue;
            }
            if (!$inAddress && $c === '[' && strtolower(substr($s, $i, 8)) === '[a href=' && self::addressCloses($s, $i + 8)) {
                $inAddress = true;
                $i += 8;
                continue;
            }
            $i++;
        }

        return $out;
    }

    private static function addressCloses(string $s, int $from): bool
    {
        $len = strlen($s);
        for ($i = $from; $i < $len; $i++) {
            if ($s[$i] === '\\' && $i + 1 < $len && str_contains(self::ESCAPABLE, $s[$i + 1])) {
                $i++;
                continue;
            }
            if ($s[$i] === ']') {
                return true;
            }
        }

        return false;
    }

    /**
     * The definition's NON-OWNER party tags — the tags whose values a starter compiles and seals —
     * lower-cased, first use first, each `['tag' => …, 'party' => …, 'field' => …]`.
     *
     * @return list<array{tag: string, party: string, field: string}>
     */
    public static function nonOwnerPartyTags(array $definition): array
    {
        $types = [];
        foreach (($definition['parties'] ?? []) as $p) {
            if (is_array($p) && is_string($p['key'] ?? null)) {
                $t = $p['type'] ?? null;
                $types[strtolower($p['key'])] = is_string($t) && $t !== '' ? $t : null;
            }
        }
        $out = [];
        $seen = [];
        foreach (($definition['nodes'] ?? []) as $node) {
            foreach ((is_array($node) ? ($node['elements'] ?? []) : []) as $el) {
                if (!is_array($el) || ($el['kind'] ?? null) !== 'text') {
                    continue;
                }
                $body = '';
                foreach (['body', 'text', 'label'] as $k) {
                    if (is_string($el[$k] ?? null) && $el[$k] !== '') {
                        $body = $el[$k];
                        break;
                    }
                }
                foreach (self::tags($body) as $tag) {
                    $dot = strpos($tag, '.');
                    if ($dot === false) {
                        continue;
                    }
                    $party = substr($tag, 0, $dot);
                    if (!array_key_exists($party, $types) || $types[$party] === 'owner' || isset($seen[$tag])) {
                        continue;
                    }
                    $seen[$tag] = true;
                    $out[] = ['tag' => $tag, 'party' => $party, 'field' => substr($tag, $dot + 1)];
                }
            }
        }

        return $out;
    }
}
