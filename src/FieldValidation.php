<?php

declare(strict_types=1);

namespace Allus\CompanyData;

/**
 * Country-data helpers.
 *
 * What a value must satisfy for its field TYPE lives in
 * {@see \Allus\CompanyData\Model\FieldTypes}: a type is a row in the served registry and that
 * class is the one interpreter of those rows. These two helpers are about the bundled country
 * dataset itself, which no registry row carries.
 */
final class FieldValidation
{
    /** True if {@code $code} is an assigned ISO 3166-1 alpha-2 country code. */
    public static function isValidCountryCode(?string $code): bool
    {
        return $code !== null && in_array($code, CountryData::COUNTRY_CODES, true);
    }

    /** The ITU E.164 dial code (digits only, no {@code +}) for a country code, or null. */
    public static function dialCodeFor(?string $code): ?string
    {
        return $code === null ? null : (CountryData::DIAL_CODES[$code] ?? null);
    }
}
