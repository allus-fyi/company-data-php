<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\FieldValidation;
use PHPUnit\Framework\TestCase;

/**
 * Field-type value validation parity — every case in the shared vector must pass. The same
 * {@code contract-field-validation-vector.json} pins the web reference
 * ({@code frontend/src/fieldValidation.js}) + the iOS/Android/SDK ports.
 */
final class FieldValidationTest extends TestCase
{
    private const VECTOR = __DIR__ . '/../testdata/contract-field-validation-vector.json';

    /** @return array<string, array{0: string, 1: string, 2: string, 3: bool}> */
    public static function cases(): array
    {
        $raw = file_get_contents(self::VECTOR);
        if ($raw === false) {
            throw new \RuntimeException('could not read the field-validation vector');
        }
        /** @var array{cases: list<array<string,mixed>>} $doc */
        $doc = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($doc['cases'] as $c) {
            $out[(string) $c['name']] = [(string) $c['name'], (string) $c['type'], (string) $c['value'], (bool) $c['valid']];
        }
        return $out;
    }

    /** @dataProvider cases */
    public function testVectorCase(string $name, string $type, string $value, bool $valid): void
    {
        self::assertSame($valid, FieldValidation::isFieldValueValid($type, $value), $name);
    }

    public function testVectorHasAllCases(): void
    {
        self::assertCount(115, self::cases());
    }

    public function testFieldValueErrorTag(): void
    {
        self::assertNull(FieldValidation::fieldValueError('email', 'a@b.co'));
        self::assertSame('email', FieldValidation::fieldValueError('email', 'nope'));
        self::assertNull(FieldValidation::fieldValueError('text', 'anything'));
    }
}
