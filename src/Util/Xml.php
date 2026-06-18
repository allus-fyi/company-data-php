<?php

declare(strict_types=1);

namespace Allus\CompanyData\Util;

/**
 * XXE-safe parser for the platform's XML serialization.
 *
 * Mirrors the platform serializer:
 *
 * - the document root is {@code <response>};
 * - a PHP list (int keys) renders as repeated {@code <item>} children — so an
 *   element whose every child is {@code <item>} becomes a PHP list;
 * - an associative array renders as named child tags — a PHP assoc array;
 * - scalars are element text; booleans were written as {@code "true"}/{@code "false"}.
 *
 * **XXE-safe:** parsed with {@code LIBXML_NONET} (no
 * network), DOCTYPE rejected outright (no entity definitions reach the parser),
 * and {@code LIBXML_NOENT} is NEVER set (so even if an entity slipped through it
 * would not be substituted). On modern libxml2 external-entity loading is off by
 * default — we keep it off and never install an external-entity loader. The HMAC
 * (webhooks) is always computed over the RAW bytes, never this parsed tree.
 */
final class Xml
{
    /**
     * Parse the platform's XML serialization into PHP data.
     *
     * @return array<string,mixed>|list<mixed>|string
     *
     * @throws \RuntimeException on a parse failure or a rejected DOCTYPE.
     */
    public static function parse(string $text): array|string
    {
        // Reject any DOCTYPE before parsing — that is the XXE entry point. The
        // platform never emits one; rejecting it removes the entire attack class.
        if (preg_match('/<!DOCTYPE/i', $text) === 1) {
            throw new \RuntimeException('XML DOCTYPE is not allowed (XXE protection)');
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            // LIBXML_NONET: no network access. NOT LIBXML_NOENT: entities are
            // never substituted. NoDTD is implied (we already rejected DOCTYPE).
            $loaded = $doc->loadXML($text, LIBXML_NONET | LIBXML_NOCDATA);
            if ($loaded === false || $doc->documentElement === null) {
                $err = libxml_get_last_error();
                throw new \RuntimeException(
                    'response was not valid XML' . ($err ? ': ' . trim($err->message) : '')
                );
            }
            // Defensive: even though DOCTYPE was rejected above, refuse any
            // entity-reference nodes that might exist.
            self::assertNoEntities($doc->documentElement);

            return self::elementToPhp($doc->documentElement);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * @return array<string,mixed>|list<mixed>|string
     */
    private static function elementToPhp(\DOMElement $elem): array|string
    {
        $children = [];
        foreach ($elem->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $children[] = $node;
            }
        }

        if ($children === []) {
            // A leaf node: its text (or empty string). Callers coerce types from
            // the known schema; booleans came over as "true"/"false".
            return $elem->textContent;
        }

        // All children are <item> → a list (PHP int-keyed array).
        $allItems = true;
        foreach ($children as $child) {
            if ($child->tagName !== 'item') {
                $allItems = false;
                break;
            }
        }
        if ($allItems) {
            $list = [];
            foreach ($children as $child) {
                $list[] = self::elementToPhp($child);
            }
            return $list;
        }

        // Otherwise an object: named tags → keys. Repeated tags collapse to a list.
        $result = [];
        foreach ($children as $child) {
            $value = self::elementToPhp($child);
            $tag = $child->tagName;
            if (array_key_exists($tag, $result)) {
                $existing = $result[$tag];
                if (is_array($existing) && array_is_list($existing)) {
                    $existing[] = $value;
                    $result[$tag] = $existing;
                } else {
                    $result[$tag] = [$existing, $value];
                }
            } else {
                $result[$tag] = $value;
            }
        }
        return $result;
    }

    private static function assertNoEntities(\DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMEntityReference) {
                throw new \RuntimeException('XML entity references are not allowed (XXE protection)');
            }
            if ($child->hasChildNodes()) {
                self::assertNoEntities($child);
            }
        }
    }
}
