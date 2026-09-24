<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Model\FieldTypes;
use PHPUnit\Framework\TestCase;

/**
 * Field-type registry parity — every case in the shared
 * {@code contract-field-validation-vector.json} vector must pass; that vector is the contract
 * {@see FieldTypes} is held to. Its {@code registry} member is the row set every case is
 * resolved against.
 */
final class FieldValidationTest extends TestCase
{
    private const VECTOR = __DIR__ . '/../testdata/contract-field-validation-vector.json';

    /** @return array<string,mixed> */
    private static function vector(): array
    {
        $raw = file_get_contents(self::VECTOR);
        if ($raw === false) {
            throw new \RuntimeException('could not read the field-validation vector');
        }
        /** @var array<string,mixed> $doc */
        $doc = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        return $doc;
    }

    private static function registry(): FieldTypes
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = self::vector()['registry'];
        return new FieldTypes($rows);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: ?list<string>, 4: bool}> */
    public static function cases(): array
    {
        $out = [];
        foreach (self::vector()['cases'] as $c) {
            // `options` is the caller's own option list, present only on a choice case.
            $options = isset($c['options']) && is_array($c['options'])
                ? array_map(strval(...), $c['options'])
                : null;
            $out[(string) $c['name']] = [
                (string) $c['name'], (string) $c['type'], (string) $c['value'], $options, (bool) $c['valid'],
            ];
        }
        return $out;
    }

    /**
     * @param ?list<string> $options
     * @dataProvider cases
     */
    public function testVectorCase(string $name, string $type, string $value, ?array $options, bool $valid): void
    {
        self::assertSame($valid, self::registry()->isFieldValueValid($type, $value, $options), $name);
    }

    public function testVectorHasAllCases(): void
    {
        self::assertCount(179, self::cases());
    }

    public function testResolveCases(): void
    {
        $registry = self::registry();
        foreach (self::vector()['resolve_cases'] as $c) {
            self::assertSame(
                json_encode($c['resolved']),
                json_encode($registry->resolve((string) $c['type'])),
                (string) $c['name'],
            );
        }
    }

    public function testAcceptsCases(): void
    {
        $registry = self::registry();
        foreach (self::vector()['accepts_cases'] as $c) {
            self::assertSame(
                (bool) $c['accepts'],
                $registry->accepts((string) $c['requested'], (string) $c['actual']),
                (string) $c['name'],
            );
        }
    }

    public function testOrderedCases(): void
    {
        $registry = self::registry();
        foreach (self::vector()['ordered_cases'] as $c) {
            self::assertSame($c['ordered'], $registry->ordered($c['types']), (string) $c['name']);
        }
    }

    public function testEffectiveTypeCases(): void
    {
        $registry = self::registry();
        foreach (self::vector()['effective_type_cases'] as $c) {
            self::assertSame(
                (string) $c['effective_type'],
                $registry->effectiveType((string) $c['type']),
                (string) $c['name'],
            );
        }
    }

    public function testDerivedSets(): void
    {
        $registry = self::registry();
        $sets = self::vector()['derived_sets'];
        self::assertSame($sets['requestable_types'], $registry->requestableTypes());
        self::assertSame($sets['flow_types'], $registry->flowTypes());
        self::assertSame($sets['claimable_types'], $registry->claimableTypes());
    }

    public function testFieldValueErrorTag(): void
    {
        $registry = self::registry();
        self::assertNull($registry->fieldValueError('email', 'a@b.co'));
        self::assertSame('validation', $registry->fieldValueError('email', 'nope'));
        self::assertNull($registry->fieldValueError('text', 'anything'));
    }
}
