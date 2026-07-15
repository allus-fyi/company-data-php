<?php

declare(strict_types=1);

namespace Allus\CompanyData;

/**
 * Shared field-type value validation (#302).
 *
 * Pure + i18n-free port of the web reference {@code frontend/src/fieldValidation.js},
 * kept byte-aligned across web / allus / iOS / Android / the 6 SDKs by
 * {@code testdata/contract-field-validation-vector.json}. Spec:
 * {@code docs/superpowers/specs/2026-07-15-field-type-validation-design.html}.
 *
 * Contract: {@see isFieldValueValid()} returns true/false for (type, value). Empty
 * value = valid (required is the caller's job). Only present, non-empty sub-fields of
 * a structured type are checked. An unknown / {@code text} type accepts anything.
 *
 * The SDK validates the PLAINTEXT before it is encrypted, at the value-submit
 * surfaces only (never on share / propagate).
 */
final class FieldValidation
{
    private const EMAIL_RE = '~^[^\s@]+@[^\s@]+\.[^\s@]+$~';
    private const URL_RE = '~^https?://[^\s/$.?#][^\s]*\.[^\s]{2,}$~i';
    private const MIME_RE = '~^[\w.+-]+/[\w.+-]+$~';
    private const PHONE_RE = '~^\+?\d{4,15}$~';
    private const CARD_RE = '~^\d{12,19}$~';
    private const DATE_RE = '~^\d{4}-\d{2}-\d{2}$~';

    /** @var list<string> */
    private const GENDER = ['Male', 'Female', 'Non-binary', 'Prefer not to say'];

    /**
     * structured types: each allowed key -> sub-rule.
     *   []              = any string
     *   ['int' => true] = JSON integer
     *   ['re' => ...]   = string matching a regex
     *   ['kind' => ...] = reuse a kind handler ('card', 'date')
     *
     * @var array<string, array<string, array{re?:string, int?:bool, kind?:string}>>
     */
    private const OBJ = [
        'address' => [
            'postal_code' => ['re' => '~^[A-Za-z0-9][A-Za-z0-9 -]{1,9}$~'],
            'country' => ['kind' => 'countryCode'], 'state' => ['kind' => 'usState'],
            'street' => [], 'building_number' => [], 'affix' => [], 'city' => [],
        ],
        'creditcard' => [
            'number' => ['kind' => 'card'],
            'expiry' => ['re' => '~^(0[1-9]|1[0-2])/\d{2}(\d{2})?$~'],
            'cvc' => ['re' => '~^\d{3,4}$~'],
            'name' => [],
        ],
        'bank' => [
            'swift' => ['re' => '~^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$~'],
            'routing_number' => ['re' => '~^\d{9}$~'],
            'account_number' => ['re' => '~^[A-Za-z0-9 ]{4,34}$~'],
            'account_holder' => [], 'bank_name' => [],
        ],
        'document' => [
            'size' => ['int' => true], 'mime_type' => ['re' => self::MIME_RE],
            'name' => [], 'file' => [], 'original_name' => [],
        ],
        'legal_document' => [
            'size' => ['int' => true], 'expiry_date' => ['kind' => 'date'], 'mime_type' => ['re' => self::MIME_RE],
            'document_number' => [], 'file' => [], 'original_name' => [],
        ],
    ];

    /** @var array<string, array{kind:string, re?:string, values?:list<string>}> */
    private const RULES = [
        'email' => ['kind' => 'regex', 're' => self::EMAIL_RE],
        'phone' => ['kind' => 'phone'],
        'url' => ['kind' => 'url'],
        'date' => ['kind' => 'date'], 'date_of_birth' => ['kind' => 'date'],
        'gender' => ['kind' => 'enum', 'values' => self::GENDER],
        'address' => ['kind' => 'object'], 'creditcard' => ['kind' => 'object'], 'bank' => ['kind' => 'object'],
        'document' => ['kind' => 'object'], 'legal_document' => ['kind' => 'object'],
        'number' => ['kind' => 'number'], 'boolean' => ['kind' => 'boolean'],
        'country' => ['kind' => 'countryCode'], 'nationality' => ['kind' => 'countryCode'],
        // text + unknown => no rule => accept anything
    ];

    /** True if {@code $value} is an acceptable plaintext for {@code $type}. Empty value is valid. */
    public static function isFieldValueValid(?string $type, mixed $value): bool
    {
        $s = $value === null ? '' : (string) $value;
        if ($s === '') {
            return true;
        }
        $rule = self::RULES[$type ?? ''] ?? null;
        if ($rule === null) {
            return true;
        }
        return match ($rule['kind']) {
            'regex' => preg_match($rule['re'], $s) === 1,
            'enum' => in_array($s, $rule['values'], true),
            'object' => self::validObject((string) $type, $s),
            default => self::applyKind($rule['kind'], $s),
        };
    }

    /** Null when valid, else the {@code $type} tag (callers map to {@code field_invalid_<type>}). */
    public static function fieldValueError(?string $type, mixed $value): ?string
    {
        return self::isFieldValueValid($type, $value) ? null : ($type ?? '');
    }

    /** True if {@code $code} is an assigned ISO 3166-1 alpha-2 country code (#303). */
    public static function isValidCountryCode(?string $code): bool
    {
        return $code !== null && in_array($code, CountryData::COUNTRY_CODES, true);
    }

    /** The ITU E.164 dial code (digits only, no {@code +}) for a country code, or null (#303). */
    public static function dialCodeFor(?string $code): ?string
    {
        return $code === null ? null : (CountryData::DIAL_CODES[$code] ?? null);
    }

    /** kind handlers for the "content" checks (top-level rules AND structured sub-rules). */
    private static function applyKind(string $kind, string $value): bool
    {
        switch ($kind) {
            case 'phone':
                return preg_match(self::PHONE_RE, (string) preg_replace('~[ \-().]~', '', $value)) === 1;
            case 'url':
                $u = preg_match('~^https?://~i', $value) === 1 ? $value : 'https://' . $value;
                return preg_match(self::URL_RE, $u) === 1;
            case 'date':
                return self::validDate($value);
            case 'card':
                $s = (string) preg_replace('~[ -]~', '', $value);
                return preg_match(self::CARD_RE, $s) === 1 && self::luhnOk($s);
            case 'number':
                $t = trim($value);
                return $t !== '' && is_numeric($t) && is_finite((float) $t);
            case 'boolean':
                return $value === 'true' || $value === 'false';
            case 'countryCode':
                return in_array($value, CountryData::COUNTRY_CODES, true);
            case 'usState':
                return in_array($value, CountryData::US_STATE_CODES, true);
            default:
                return true;
        }
    }

    private static function validObject(string $type, string $raw): bool
    {
        $o = json_decode($raw, false);
        // A JSON object decodes to stdClass; arrays/scalars/null are rejected.
        if (!is_object($o)) {
            return false;
        }
        $spec = self::OBJ[$type];
        foreach (get_object_vars($o) as $k => $v) {
            if (!array_key_exists($k, $spec)) {
                return false; // unknown key
            }
            $sub = $spec[$k];
            if ($sub['int'] ?? false) {
                // JSON integer (matches JS Number.isInteger — a whole-valued number, never a bool/string).
                if (is_bool($v) || !(is_int($v) || (is_float($v) && is_finite($v) && floor($v) === $v))) {
                    return false;
                }
                continue;
            }
            if (!is_string($v)) {
                return false;
            }
            if ($v === '') {
                continue; // empty sub-field ok (partial fill)
            }
            if (isset($sub['re']) && preg_match($sub['re'], $v) !== 1) {
                return false;
            }
            if (isset($sub['kind']) && !self::applyKind($sub['kind'], $v)) {
                return false;
            }
        }
        return true;
    }

    private static function validDate(string $s): bool
    {
        if (preg_match(self::DATE_RE, $s) !== 1) {
            return false;
        }
        $y = (int) substr($s, 0, 4);
        $m = (int) substr($s, 5, 2);
        $d = (int) substr($s, 8, 2);
        if ($m < 1 || $m > 12) {
            return false;
        }
        return $d >= 1 && $d <= self::daysInMonth($y, $m);
    }

    private static function daysInMonth(int $y, int $m): int
    {
        if ($m === 2) {
            $leap = ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0;
            return $leap ? 29 : 28;
        }
        return [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$m - 1];
    }

    private static function luhnOk(string $digits): bool
    {
        $sum = 0;
        $dbl = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = ord($digits[$i]) - 48;
            if ($d < 0 || $d > 9) {
                return false;
            }
            if ($dbl) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $dbl = !$dbl;
        }
        return $sum % 10 === 0;
    }
}
