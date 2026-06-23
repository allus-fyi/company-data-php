<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\FlowCondition;
use PHPUnit\Framework\TestCase;

/**
 * FlowConditionEvaluator parity — every case in the shared vector must pass. The same vector pins
 * the PHP reference + the python/ts/go/csharp/java/iOS/Android ports.
 */
final class FlowConditionTest extends TestCase
{
    private const VECTOR = __DIR__ . '/../testdata/contract-flow-condition-vector.json';

    /** @return list<array{0: string, 1: mixed, 2: array<string,mixed>, 3: bool}> */
    public static function cases(): array
    {
        $raw = file_get_contents(self::VECTOR);
        if ($raw === false) {
            throw new \RuntimeException('could not read the flow-condition vector');
        }
        /** @var array{cases: list<array<string,mixed>>} $doc */
        $doc = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($doc['cases'] as $c) {
            $answers = is_array($c['answers'] ?? null) ? $c['answers'] : [];
            $out[(string) $c['name']] = [(string) $c['name'], $c['condition'] ?? null, $answers, (bool) $c['expect']];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $answers
     *
     * @dataProvider cases
     */
    public function testVectorCase(string $name, mixed $condition, array $answers, bool $expect): void
    {
        self::assertSame($expect, FlowCondition::evaluate($condition, $answers), $name);
    }

    public function testVectorHasAllCases(): void
    {
        self::assertCount(27, self::cases());
    }
}
