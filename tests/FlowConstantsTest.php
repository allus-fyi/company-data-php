<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\FlowCondition;
use PHPUnit\Framework\TestCase;

/**
 * Flow-constants (computed variables, issue #79) parity — every case in the shared
 * contract-flow-constants-vector.json must pass. The same vector pins the JS reference and the
 * python/ts/go/csharp/java/iOS/Android ports. computeConstants() extends the frozen
 * FlowCondition evaluator (its 27-case condition vector is untouched).
 */
final class FlowConstantsTest extends TestCase
{
    private const VECTOR = __DIR__ . '/../testdata/contract-flow-constants-vector.json';

    /** @return array<string, array{0:string, 1:list<mixed>, 2:array<string,mixed>, 3:?string, 4:array<string,mixed>}> */
    public static function cases(): array
    {
        $raw = file_get_contents(self::VECTOR);
        if ($raw === false) {
            throw new \RuntimeException('could not read the flow-constants vector');
        }
        /** @var array{cases: list<array<string,mixed>>} $doc */
        $doc = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($doc['cases'] as $c) {
            $constants = is_array($c['constants'] ?? null) ? array_values($c['constants']) : [];
            $answers = is_array($c['answers'] ?? null) ? $c['answers'] : [];
            $refDate = isset($c['reference_date']) ? (string) $c['reference_date'] : null;
            $expect = is_array($c['expect'] ?? null) ? $c['expect'] : [];
            $out[(string) $c['name']] = [(string) $c['name'], $constants, $answers, $refDate, $expect];
        }
        return $out;
    }

    /**
     * @param list<mixed>         $constants
     * @param array<string,mixed> $answers
     * @param array<string,mixed> $expect
     *
     * @dataProvider cases
     */
    public function testVectorCase(string $name, array $constants, array $answers, ?string $refDate, array $expect): void
    {
        $result = FlowCondition::computeConstants($constants, $answers, $refDate);
        foreach ($expect as $key => $want) {
            self::assertArrayHasKey($key, $result, "$name: missing constant '$key'");
            self::assertVectorEquals($want, $result[$key], "$name: constant '$key'");
        }
    }

    public function testVectorHasAllCases(): void
    {
        self::assertCount(51, self::cases());
    }

    /**
     * null/bool/string are strict; numbers are compared as floats (PHP math yields float,
     * JSON int expects decode to int — a strict compare would misfire on type only).
     */
    private static function assertVectorEquals(mixed $expected, mixed $actual, string $msg): void
    {
        if ((is_int($expected) || is_float($expected)) && !is_bool($expected)) {
            self::assertTrue(
                (is_int($actual) || is_float($actual)) && !is_bool($actual) && (float) $actual === (float) $expected,
                $msg . ' (expected numeric ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
            );

            return;
        }
        self::assertSame($expected, $actual, $msg);
    }
}
