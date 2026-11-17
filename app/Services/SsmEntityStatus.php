<?php

namespace App\Services;

/**
 * Derives what CIDP's get-status-entity returns as `companyStatus` from a case's saved
 * profile, so the mock answers with the same wording as prod (AE-522).
 *
 * ROC and ROB codes map through SSM's reference tables (ref_ROB_BusinessStatusCode and
 * the ROC status table attached to AE-522); the labels are copied verbatim, including
 * the mixed-case `Existing (Under CVS)`. LLP profiles already carry prod's wording in
 * `entityStatusDesc`. Prod also reports an ACTIVE business as EXPIRED once its
 * `endBusinessDate` has passed, which the profile code alone does not show.
 */
final class SsmEntityStatus
{
    public const ROC = [
        'E' => 'EXISTING',
        'W' => 'WINDING UP',
        'D' => 'DISSOLVED',
        'R' => 'REMOVED',
        'C' => 'CEASED BUSINESS',
        'X' => 'NULL & VOID PURSUANT COURT ORDER',
        'B' => 'DISSOLVED (CONVERSION TO LLP)',
        'Y' => 'STRUCK-OFF & WINDING-UP VIA COURT ORDER',
        'F' => 'WINDING UP (UNDER CVA)',
        'G' => 'EXISTING (UNDER CVA)',
        'J' => 'EXISTING (UNDER JM)',
        'V' => 'Existing (Under CVS)',
    ];

    public const ROB = [
        'A' => 'ACTIVE',
        'L' => 'EXPIRED',
        'T' => 'TERMINATED',
        'B' => 'DISSOLVED-CONVERSION TO LIMITED LIABILITY PARTNERSHIP(LLP)',
    ];

    private const ENTITY_TYPE_LABELS = [
        'company' => 'Company',
        'business' => 'Business',
        'llp' => 'Limited Liability Partnerships',
    ];

    /**
     * @param  array  $meta  the case's meta.json; a non-blank `status` overrides everything
     * @param  string  $entityType  meta.json vocabulary: company | business | llp
     * @param  array|null  $profile  decoded profile JSON, null when the case has none
     */
    public static function status(array $meta, string $entityType, ?array $profile, \DateTimeInterface $now): string
    {
        $override = $meta['status'] ?? null;

        if (is_string($override) && trim($override) !== '') {
            return $override;
        }

        $derived = match ($entityType) {
            'company' => self::rocStatus($profile),
            'business' => self::robStatus($profile, $now),
            'llp' => self::llpStatus($profile),
            default => null,
        };

        return $derived ?? self::fallback($entityType);
    }

    /** The pre-2019 registration number as CIDP returns it in `oldCompanyNo`; '' if unknown. */
    public static function oldRegNo(string $entityType, ?array $profile): string
    {
        return match ($entityType) {
            'company' => self::joinWithCheckDigit(
                $profile['getCompProfile']['rocCompanyInfo']['companyNo'] ?? null,
                $profile['getCompProfile']['rocCompanyInfo']['checkDigit'] ?? null,
            ),
            'business' => self::joinWithCheckDigit(
                $profile['getBizProfile']['robBusinessInfo']['registrationNo'] ?? null,
                $profile['getBizProfile']['robBusinessInfo']['checkDigit'] ?? null,
            ),
            'llp' => self::stringOrEmpty($profile['getLlpCurrentProfile']['llpCurrentProfile']['llpBasicProfile']['entityNo'] ?? null),
            default => '',
        };
    }

    public static function entityTypeLabel(string $entityType): string
    {
        return self::ENTITY_TYPE_LABELS[$entityType] ?? $entityType;
    }

    private static function rocStatus(?array $profile): ?string
    {
        $code = self::code($profile['getCompProfile']['rocCompanyInfo']['statusOfCompany'] ?? null);

        return self::ROC[$code] ?? null;
    }

    private static function robStatus(?array $profile, \DateTimeInterface $now): ?string
    {
        $info = $profile['getBizProfile']['robBusinessInfo'] ?? null;
        $code = self::code($info['status'] ?? null);

        if (! isset(self::ROB[$code])) {
            return null;
        }

        if ($code === 'A' && self::hasEnded($info['endBusinessDate'] ?? null, $now)) {
            return self::ROB['L'];
        }

        return self::ROB[$code];
    }

    private static function llpStatus(?array $profile): ?string
    {
        $description = $profile['getLlpCurrentProfile']['llpCurrentProfile']['llpBasicProfile']['entityStatusDesc'] ?? null;

        if (! is_string($description) || trim($description) === '') {
            return null;
        }

        return trim($description);
    }

    private static function fallback(string $entityType): string
    {
        return $entityType === 'business' ? self::ROB['A'] : self::ROC['E'];
    }

    /**
     * SSM stores the end of a registration as an ISO-8601 instant (Malaysia midnight is
     * `T16:00:00.000Z` of the previous UTC day), so comparing instants is the same as
     * comparing Malaysia calendar dates. Anything that is not an ISO date never expires.
     */
    private static function hasEnded(mixed $endBusinessDate, \DateTimeInterface $now): bool
    {
        if (! is_string($endBusinessDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', trim($endBusinessDate))) {
            return false;
        }

        try {
            $end = new \DateTimeImmutable(trim($endBusinessDate));
        } catch (\Exception) {
            return false;
        }

        return $now >= $end;
    }

    private static function code(mixed $value): string
    {
        return is_string($value) ? strtoupper(trim($value)) : '';
    }

    private static function joinWithCheckDigit(mixed $number, mixed $checkDigit): string
    {
        $number = self::stringOrEmpty($number);
        $checkDigit = self::stringOrEmpty($checkDigit);

        if ($number === '') {
            return '';
        }

        return $checkDigit === '' ? $number : $number.'-'.$checkDigit;
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
