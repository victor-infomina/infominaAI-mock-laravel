<?php

namespace App\Services;

/**
 * The real gateway's getImageView entries carry two date fields for one date:
 * documentDate as "YYYYMMDD" (what the BE and FE parse) and dateFiler as an
 * ISO-8601 timestamp in Malaysia time. Both are derived from any date-ish
 * string here so case folders can be built from DB rows, CSV exports or
 * hand-written lists alike.
 */
final class IdamanDocumentDates
{
    private const TZ = '+08:00';

    public static function yyyymmdd(string $date): string
    {
        return self::parse($date)->format('Ymd');
    }

    public static function iso(string $date): string
    {
        return self::parse($date)->format('Y-m-d\TH:i:s').self::TZ;
    }

    private static function parse(string $date): \DateTimeImmutable
    {
        $value = trim($date);

        if (preg_match('/^\d{8}$/', $value)) {
            $value = substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2);
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone(self::TZ));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Unrecognised Idaman document date \"{$date}\"", 0, $e);
        }
    }
}
