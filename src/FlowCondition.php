<?php

declare(strict_types=1);

namespace Allus\CompanyData;

/**
 * Pure port of the platform FlowConditionEvaluator (A-spec §4) — pinned to the shared
 * contract-flow-condition-vector.json.
 *
 * A condition is one of:
 *   - null / a non-array → always true (the "no condition" short-circuit).
 *   - a boolean node {op:"and"|"or"|"not", children:[...]} (not = one child).
 *   - a comparison leaf {field, op, value} with op in eq ne lt le gt ge in nin answered empty.
 *
 * $answers is the decrypted {slug: value} map.
 *
 * Frozen semantics (see the vector):
 *   - A blank/missing answer is "unanswered": never matches eq/ne/an ordered comparison (→ false);
 *     empty true, answered false; nin true on missing.
 *   - eq/ne: booleans by truth, numbers (with numeric-string coercion) by value, else strings
 *     exactly. in/nin: membership in the array value.
 *   - Ordered (lt/le/gt/ge): BOTH numeric → numeric compare; BOTH non-numeric → string compare
 *     (so YYYY-MM-DD dates sort chronologically); MIXED → false.
 *   - and over [] → true; or over [] → false.
 */
final class FlowCondition
{
    /**
     * Evaluate a parsed condition (associative array / null) against the decrypted answer map.
     *
     * @param mixed                $condition the condition (a parsed JSON object, or null)
     * @param array<string, mixed> $answers   the decrypted answer map
     */
    public static function evaluate(mixed $condition, array $answers): bool
    {
        if (!is_array($condition)) {
            return true; // null / non-array = true
        }
        $op = is_string($condition['op'] ?? null) ? $condition['op'] : '';
        if ($op === 'and' || $op === 'or' || $op === 'not') {
            $kids = is_array($condition['children'] ?? null) ? $condition['children'] : [];
            $kids = array_values($kids);
            return match ($op) {
                'and' => self::all($kids, $answers),
                'or' => self::any($kids, $answers),
                default => !self::evaluate($kids[0] ?? null, $answers), // not
            };
        }

        $slug = is_string($condition['field'] ?? null) ? $condition['field'] : '';
        $target = $condition['value'] ?? null;
        $val = array_key_exists($slug, $answers) ? $answers[$slug] : null;

        switch ($op) {
            case 'answered':
                return self::answered($val);
            case 'empty':
                return !self::answered($val);
            case 'in':
                return self::inList($target, $val);
            case 'nin':
                return !self::inList($target, $val);
        }

        if (!self::answered($val)) {
            return false;
        }
        switch ($op) {
            case 'eq':
                return self::looseEq($target, $val);
            case 'ne':
                return !self::looseEq($target, $val);
            case 'lt':
            case 'gt':
            case 'le':
            case 'ge':
                $a = self::toNum($val);
                $b = self::toNum($target);
                if ($a !== null && $b !== null) {
                    return self::cmpNum($op, $a, $b);
                }
                // Mixed (one numeric, one not) → false; both non-numeric → string compare.
                if ($a !== null || $b !== null) {
                    return false;
                }
                return self::cmpStr($op, self::str($val), self::str($target));
            default:
                return false;
        }
    }

    /** @param list<mixed> $kids @param array<string, mixed> $answers */
    private static function all(array $kids, array $answers): bool
    {
        foreach ($kids as $c) {
            if (!self::evaluate($c, $answers)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<mixed> $kids @param array<string, mixed> $answers */
    private static function any(array $kids, array $answers): bool
    {
        foreach ($kids as $c) {
            if (self::evaluate($c, $answers)) {
                return true;
            }
        }
        return false;
    }

    private static function answered(mixed $v): bool
    {
        if ($v === null) {
            return false;
        }
        if (is_string($v)) {
            return $v !== '';
        }
        return true;
    }

    private static function inList(mixed $target, mixed $val): bool
    {
        if (!is_array($target)) {
            return false;
        }
        foreach ($target as $x) {
            if (self::looseEq($x, $val)) {
                return true;
            }
        }
        return false;
    }

    private static function toNum(mixed $v): ?float
    {
        if (is_bool($v)) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v)) {
            $t = trim($v);
            if ($t === '' || !is_numeric($t)) {
                return null;
            }
            return (float) $t;
        }
        return null;
    }

    private static function looseEq(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return self::truthy($a) === self::truthy($b);
        }
        $na = self::toNum($a);
        $nb = self::toNum($b);
        if ($na !== null && $nb !== null) {
            return $na === $nb;
        }
        return self::str($a) === self::str($b);
    }

    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null) {
            return false;
        }
        if (is_string($v)) {
            return $v !== '';
        }
        $n = self::toNum($v);
        return $n !== null ? $n !== 0.0 : true;
    }

    private static function str(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return $v == floor($v) && is_finite($v) ? (string) (int) $v : (string) $v;
        }
        if (is_string($v)) {
            return $v;
        }
        return (string) (is_scalar($v) ? $v : '');
    }

    private static function cmpNum(string $op, float $a, float $b): bool
    {
        return match ($op) {
            'lt' => $a < $b,
            'gt' => $a > $b,
            'le' => $a <= $b,
            default => $a >= $b, // ge
        };
    }

    private static function cmpStr(string $op, string $a, string $b): bool
    {
        $c = strcmp($a, $b);
        return match ($op) {
            'lt' => $c < 0,
            'gt' => $c > 0,
            'le' => $c <= 0,
            default => $c >= 0, // ge
        };
    }
}
