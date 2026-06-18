<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * Small coercion helpers shared by the model factories.
 *
 * Tolerant of both JSON values and the XML "true"/"false" strings the platform
 * serializer emits ({@see \Allus\CompanyData\Util\Xml}).
 */
final class Coerce
{
    /** Coerce a JSON bool or an XML "true"/"false" string into a bool, or null. */
    public static function bool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $low = strtolower(trim($value));
            if ($low === 'true' || $low === '1') {
                return true;
            }
            if ($low === 'false' || $low === '0' || $low === '') {
                return false;
            }
        }
        return (bool) $value;
    }

    /**
     * Parse an API ISO-8601 timestamp into a DateTimeImmutable (tolerant of 'Z').
     */
    public static function dateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = (string) $value;
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parse an ISO {@code YYYY-MM-DD} (the leading 10 chars) into a date-only
     * DateTimeImmutable at midnight UTC; null if unparseable.
     */
    public static function date(string $value): ?\DateTimeImmutable
    {
        $head = substr(trim($value), 0, 10);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $head, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return null;
        }
        // Reject "creative" parses (e.g. 2026-13-99) that createFromFormat rolls over.
        if ($dt->format('Y-m-d') !== $head) {
            return null;
        }
        return $dt;
    }
}
