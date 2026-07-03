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
            // #102 substring ops (text): contains needs an answer (like in); not_contains is
            // true when unanswered (like nin). Case-sensitive; empty needle counts as contained.
            case 'contains':
                return self::answered($val) && str_contains(self::str($val), self::str($target));
            case 'not_contains':
                return !(self::answered($val) && str_contains(self::str($val), self::str($target)));
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

    // ── Flow constants (computed variables) — issue #79. Pure; extends the evaluator above. ──
    // Reuses the shipped private helpers toNum / str (=stringOf) / evaluate (=evaluateCondition)
    // WITHOUT modifying them, so the 27-case condition vector stays byte-identical. A "constant" =
    // ['key','label','result_type','expr']. computeConstants materialises each constant's value
    // into a NEW slug=>value map (answers + [key=>value]) in dependency order, so the evaluator's
    // leaf path ['field'=><key>] references a constant with zero change. null propagates: an
    // unresolved operand yields null; a null constant behaves like an unanswered field.
    // Pinned by testdata/contract-flow-constants-vector.json (51 cases).

    /**
     * Materialise every constant into a NEW map = $answers + [key => value], evaluated in
     * topological (dependency) order. A ref to an operand not yet in the map resolves to null;
     * null propagates. Declared array order is irrelevant — the DFS post-order guarantees each
     * constant is computed after its dependencies. Cycles (rejected by the validator) are broken
     * defensively: the back-edge operand reads null.
     *
     * @param list<mixed>         $constants parsed constant objects
     * @param array<string,mixed> $answers   the decrypted slug => value map
     *
     * @return array<string,mixed>
     */
    public static function computeConstants(array $constants, array $answers, ?string $referenceDate): array
    {
        $out = $answers;

        $byKey = [];
        foreach ($constants as $c) {
            if (is_array($c) && is_string($c['key'] ?? null)) {
                $byKey[$c['key']] = $c;
            }
        }
        $constKeys = [];
        foreach (array_keys($byKey) as $k) {
            $constKeys[(string) $k] = true;
        }

        $order = [];
        $state = []; // key => 0 visiting (grey), 1 done (black)
        foreach ($constants as $c) {
            if (is_array($c) && is_string($c['key'] ?? null)) {
                self::topoVisit($c['key'], $byKey, $constKeys, $state, $order);
            }
        }

        foreach ($order as $key) {
            $out[$key] = self::evalExpr($byKey[$key]['expr'] ?? null, $out, $referenceDate);
        }

        return $out;
    }

    /**
     * Per-call-site wrapper: materialise constants, then evaluate the condition unchanged. This is
     * the ONLY change at call sites — evaluate() and the 27-case condition vector are untouched.
     *
     * @param array<string,mixed> $answers
     * @param list<mixed>         $constants
     */
    public static function evaluateFlowCondition(mixed $condition, array $answers, array $constants, ?string $referenceDate): bool
    {
        return self::evaluate($condition, self::computeConstants($constants, $answers, $referenceDate));
    }

    /**
     * Convenience: just the resolved constant values (key => value, one entry per constant),
     * WITHOUT the original answers folded in.
     *
     * @param list<mixed>         $constants
     * @param array<string,mixed> $answers
     *
     * @return array<string,mixed>
     */
    public static function resolvedConstants(array $constants, array $answers, ?string $referenceDate): array
    {
        $full = self::computeConstants($constants, $answers, $referenceDate);
        $out = [];
        foreach ($constants as $c) {
            if (is_array($c) && is_string($c['key'] ?? null)) {
                $out[$c['key']] = $full[$c['key']] ?? null;
            }
        }

        return $out;
    }

    /**
     * 3-colour DFS post-order over the constant=>constant dependency graph. A GREY revisit is a
     * cycle back-edge and is broken (returns without appending), so the operand later reads null.
     * Dependency iteration is insertion-ordered (PHP assoc-array keys) so every port breaks the
     * same back-edge.
     *
     * @param array<string,array<string,mixed>> $byKey
     * @param array<string,bool>                $constKeys
     * @param array<string,int>                 $state
     * @param list<string>                      $order
     */
    private static function topoVisit(string $key, array $byKey, array $constKeys, array &$state, array &$order): void
    {
        if (isset($state[$key])) {
            return; // done (black), or grey => cycle back-edge: break it
        }
        $state[$key] = 0;
        $deps = [];
        self::collectExprConstRefs($byKey[$key]['expr'] ?? null, $constKeys, $deps);
        foreach (array_keys($deps) as $dep) {
            if (isset($byKey[$dep])) {
                self::topoVisit((string) $dep, $byKey, $constKeys, $state, $order);
            }
        }
        $state[$key] = 1;
        $order[] = $key; // post-order => dependencies precede dependents
    }

    /**
     * Collect the constant KEYS an expression directly references (topological-ordering only).
     *
     * @param array<string,bool> $constKeys
     * @param array<string,bool> $acc
     */
    private static function collectExprConstRefs(mixed $expr, array $constKeys, array &$acc): void
    {
        if (!is_array($expr)) {
            return;
        }
        switch ($expr['type'] ?? null) {
            case 'ref':
                $k = $expr['key'] ?? null;
                if (is_string($k) && isset($constKeys[$k])) {
                    $acc[$k] = true;
                }

                return;
            case 'lit':
            case 'today':
                return;
            case 'if':
                foreach ((is_array($expr['cases'] ?? null) ? $expr['cases'] : []) as $cs) {
                    if (is_array($cs)) {
                        self::collectCondConstRefs($cs['when'] ?? null, $constKeys, $acc); // a when-leaf may name a constant
                        self::collectExprConstRefs($cs['then'] ?? null, $constKeys, $acc);
                    }
                }
                self::collectExprConstRefs($expr['else'] ?? null, $constKeys, $acc);

                return;
            case 'concat':
                foreach ((is_array($expr['parts'] ?? null) ? $expr['parts'] : []) as $p) {
                    self::collectExprConstRefs($p, $constKeys, $acc);
                }

                return;
            case 'datediff':
                self::collectExprConstRefs($expr['from'] ?? null, $constKeys, $acc);
                self::collectExprConstRefs($expr['to'] ?? null, $constKeys, $acc);

                return;
            case 'math':
                foreach ((is_array($expr['args'] ?? null) ? $expr['args'] : []) as $a) {
                    self::collectExprConstRefs($a, $constKeys, $acc);
                }

                return;
        }
    }

    /**
     * A when-condition leaf whose 'field' names a constant is a dependency.
     *
     * @param array<string,bool> $constKeys
     * @param array<string,bool> $acc
     */
    private static function collectCondConstRefs(mixed $cond, array $constKeys, array &$acc): void
    {
        if (!is_array($cond)) {
            return;
        }
        $op = $cond['op'] ?? null;
        if ($op === 'and' || $op === 'or' || $op === 'not') {
            foreach ((is_array($cond['children'] ?? null) ? $cond['children'] : []) as $ch) {
                self::collectCondConstRefs($ch, $constKeys, $acc);
            }

            return;
        }
        $f = $cond['field'] ?? null;
        if (is_string($f) && isset($constKeys[$f])) {
            $acc[$f] = true;
        }
    }

    /**
     * evalExpr(expr, map, refDate) -> value | null. Covers every AST node type.
     *
     * @param array<string,mixed> $answers
     */
    private static function evalExpr(mixed $expr, array $answers, ?string $referenceDate): mixed
    {
        if (!is_array($expr)) {
            return null;
        }
        switch ($expr['type'] ?? null) {
            case 'lit':
                return array_key_exists('value', $expr) ? $expr['value'] : null;

            case 'ref':
                $key = is_string($expr['key'] ?? null) ? $expr['key'] : '';

                return array_key_exists($key, $answers) ? $answers[$key] : null; // absent operand -> null

            case 'today':
                return (is_string($referenceDate) && $referenceDate !== '') ? $referenceDate : null;

            case 'if':
                foreach ((is_array($expr['cases'] ?? null) ? $expr['cases'] : []) as $cs) {
                    if (is_array($cs) && self::evaluate($cs['when'] ?? null, $answers)) {
                        return self::evalExpr($cs['then'] ?? null, $answers, $referenceDate);
                    }
                }

                return self::evalExpr($expr['else'] ?? null, $answers, $referenceDate); // else is required (total function)

            case 'concat':
                $sep = is_string($expr['sep'] ?? null) ? $expr['sep'] : '';
                $strs = [];
                foreach ((is_array($expr['parts'] ?? null) ? $expr['parts'] : []) as $p) {
                    $v = self::evalExpr($p, $answers, $referenceDate);
                    $strs[] = ($v === null) ? '' : self::str($v); // null part -> ""
                }

                return implode($sep, $strs); // always text

            case 'datediff':
                $from = self::parseFlowDate(self::evalExpr($expr['from'] ?? null, $answers, $referenceDate));
                $to = self::parseFlowDate(self::evalExpr($expr['to'] ?? null, $answers, $referenceDate));
                if ($from === null || $to === null) {
                    return null; // non-date operand -> null
                }

                return match ($expr['unit'] ?? null) {
                    'days' => self::diffDays($from, $to),
                    'weeks' => intdiv(self::diffDays($from, $to), 7), // trunc toward zero
                    'months' => self::diffMonths($from, $to),
                    'years' => self::diffYears($from, $to),
                    default => null,
                };

            case 'math':
                $nums = [];
                foreach ((is_array($expr['args'] ?? null) ? $expr['args'] : []) as $a) {
                    $n = self::toNum(self::evalExpr($a, $answers, $referenceDate));
                    // any null / non-numeric (incl. boolean) arg -> null; a non-finite arg (a
                    // string like "1e309" coercing to INF) -> null. Pinned non-finite policy (C2):
                    // math never yields INF/NAN.
                    if ($n === null || !is_finite($n)) {
                        return null;
                    }
                    $nums[] = $n;
                }
                $count = count($nums);
                $n0 = $nums[0] ?? null;
                $n1 = $nums[1] ?? null;

                return match ($expr['op'] ?? null) {
                    'add' => self::finOrNull(array_sum($nums)), // identity 0, variadic
                    'mul' => self::finOrNull(array_product($nums)), // identity 1, variadic
                    'sub' => $count >= 2 ? self::finOrNull($n0 - $n1) : null,
                    'div' => ($count >= 2 && $n1 !== 0.0) ? self::finOrNull($n0 / $n1) : null, // /0 -> null
                    // fmod = truncated remainder (matches JS %); PHP's % casts to int.
                    'mod' => ($count >= 2 && $n1 !== 0.0) ? self::finOrNull(fmod($n0, $n1)) : null, // %0 -> null
                    'neg' => $count >= 1 ? self::finOrNull(-$n0) : null,
                    'abs' => $count >= 1 ? self::finOrNull(abs($n0)) : null,
                    // PHP round()'s default (PHP_ROUND_HALF_UP) is HALF AWAY FROM ZERO: 2.5->3, -2.5->-3.
                    'round' => $count >= 1 ? self::finOrNull(round($n0)) : null,
                    'floor' => $count >= 1 ? self::finOrNull(floor($n0)) : null,
                    'ceil' => $count >= 1 ? self::finOrNull(ceil($n0)) : null,
                    default => null,
                };

            default:
                return null;
        }
    }

    /** Non-finite math result (overflow, e.g. 1e308 * 1e308) -> null (pinned: math_nonfinite_is_null). */
    private static function finOrNull(int|float|null $r): int|float|null
    {
        return ($r !== null && is_finite((float) $r)) ? $r : null;
    }

    /**
     * Parse a value as a UTC-midnight calendar date. Trim BEFORE the strict anchored regex
     * (matching JS String.trim()); a round-trip check rejects impossible dates (2026-02-30).
     */
    private static function parseFlowDate(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v)) {
            return null;
        }
        $t = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) !== 1) {
            return null;
        }
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $t, new \DateTimeZone('UTC'));
        if ($dt === false || $dt->format('Y-m-d') !== $t) {
            return null; // e.g. 2026-02-30 rolls over -> round-trip mismatch
        }

        return $dt;
    }

    private static function diffDays(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        // Both operands are exact UTC midnight, so the timestamp delta is an exact multiple of
        // 86400 -> integer whole days, DST-immune, sign = to - from.
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 86400);
    }

    private static function diffMonths(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $n = ((int) $to->format('Y') - (int) $from->format('Y')) * 12
            + ((int) $to->format('n') - (int) $from->format('n'));
        if ((int) $to->format('j') < (int) $from->format('j')) {
            $n -= 1;
        }

        return $n;
    }

    private static function diffYears(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $fm = (int) $from->format('n');
        $fd = (int) $from->format('j');
        $tm = (int) $to->format('n');
        $td = (int) $to->format('j');
        $n = (int) $to->format('Y') - (int) $from->format('Y');
        if ($tm < $fm || ($tm === $fm && $td < $fd)) {
            $n -= 1; // standard age: birthday not yet reached this year
        }

        return $n;
    }
}
