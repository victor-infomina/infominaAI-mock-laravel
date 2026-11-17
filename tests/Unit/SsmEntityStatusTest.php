<?php

namespace Tests\Unit;

use App\Services\SsmEntityStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SsmEntityStatusTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-11-16T07:00:00Z');
    }

    public static function rocCodes(): array
    {
        return [
            'E' => ['E', 'EXISTING'],
            'W' => ['W', 'WINDING UP'],
            'D' => ['D', 'DISSOLVED'],
            'R' => ['R', 'REMOVED'],
            'C' => ['C', 'CEASED BUSINESS'],
            'X' => ['X', 'NULL & VOID PURSUANT COURT ORDER'],
            'B' => ['B', 'DISSOLVED (CONVERSION TO LLP)'],
            'Y' => ['Y', 'STRUCK-OFF & WINDING-UP VIA COURT ORDER'],
            'F' => ['F', 'WINDING UP (UNDER CVA)'],
            'G' => ['G', 'EXISTING (UNDER CVA)'],
            'J' => ['J', 'EXISTING (UNDER JM)'],
            'V' => ['V', 'Existing (Under CVS)'],
        ];
    }

    #[DataProvider('rocCodes')]
    public function test_maps_every_roc_code(string $code, string $expected): void
    {
        $this->assertSame($expected, SsmEntityStatus::status([], 'company', $this->companyProfile($code), $this->now));
    }

    public static function robCodes(): array
    {
        return [
            'A' => ['A', 'ACTIVE'],
            'L' => ['L', 'EXPIRED'],
            'T' => ['T', 'TERMINATED'],
            'B' => ['B', 'DISSOLVED-CONVERSION TO LIMITED LIABILITY PARTNERSHIP(LLP)'],
        ];
    }

    #[DataProvider('robCodes')]
    public function test_maps_every_rob_code(string $code, string $expected): void
    {
        $profile = $this->businessProfile($code, '2099-01-01T16:00:00.000Z');

        $this->assertSame($expected, SsmEntityStatus::status([], 'business', $profile, $this->now));
    }

    public function test_roc_code_is_trimmed_and_case_insensitive(): void
    {
        $this->assertSame('WINDING UP', SsmEntityStatus::status([], 'company', $this->companyProfile(' w '), $this->now));
    }

    public function test_roc_ignores_company_status_field(): void
    {
        $profile = ['getCompProfile' => ['rocCompanyInfo' => ['statusOfCompany' => 'D', 'companyStatus' => 'R']]];

        $this->assertSame('DISSOLVED', SsmEntityStatus::status([], 'company', $profile, $this->now));
    }

    public static function expiryCases(): array
    {
        return [
            'one second before end' => ['2026-11-16T07:00:01.000Z', 'ACTIVE'],
            'exactly at end' => ['2026-11-16T07:00:00.000Z', 'EXPIRED'],
            'one second after end' => ['2026-11-16T06:59:59.000Z', 'EXPIRED'],
            'missing date' => [null, 'ACTIVE'],
            'empty date' => ['', 'ACTIVE'],
            'unparseable date' => ['not-a-date', 'ACTIVE'],
            'relative phrase is not ISO' => ['yesterday', 'ACTIVE'],
        ];
    }

    #[DataProvider('expiryCases')]
    public function test_active_business_expires_when_end_date_reached(?string $endBusinessDate, string $expected): void
    {
        $profile = $this->businessProfile('A', $endBusinessDate);

        $this->assertSame($expected, SsmEntityStatus::status([], 'business', $profile, $this->now));
    }

    public function test_expired_code_wins_over_future_end_date(): void
    {
        $profile = $this->businessProfile('L', '2099-01-01T16:00:00.000Z');

        $this->assertSame('EXPIRED', SsmEntityStatus::status([], 'business', $profile, $this->now));
    }

    public function test_terminated_business_is_not_relabelled_by_past_end_date(): void
    {
        $profile = $this->businessProfile('T', '2020-01-01T16:00:00.000Z');

        $this->assertSame('TERMINATED', SsmEntityStatus::status([], 'business', $profile, $this->now));
    }

    public function test_llp_returns_description_as_is(): void
    {
        $this->assertSame('WINDING UP BY COURT', SsmEntityStatus::status([], 'llp', $this->llpProfile('5', 'WINDING UP BY COURT'), $this->now));
    }

    public static function emptyLlpDescriptions(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'null' => [null],
        ];
    }

    #[DataProvider('emptyLlpDescriptions')]
    public function test_llp_without_description_falls_back_to_existing(?string $description): void
    {
        $this->assertSame('EXISTING', SsmEntityStatus::status([], 'llp', $this->llpProfile('', $description), $this->now));
    }

    public function test_llp_without_basic_profile_falls_back_to_existing(): void
    {
        $profile = ['getLlpCurrentProfile' => ['llpCurrentProfile' => []]];

        $this->assertSame('EXISTING', SsmEntityStatus::status([], 'llp', $profile, $this->now));
    }

    public static function fallbacks(): array
    {
        return [
            'company without profile' => ['company', null, 'EXISTING'],
            'business without profile' => ['business', null, 'ACTIVE'],
            'llp without profile' => ['llp', null, 'EXISTING'],
            'unknown roc code' => ['company', ['getCompProfile' => ['rocCompanyInfo' => ['statusOfCompany' => 'Z']]], 'EXISTING'],
            'empty roc code' => ['company', ['getCompProfile' => ['rocCompanyInfo' => ['statusOfCompany' => '']]], 'EXISTING'],
            'unknown rob code' => ['business', ['getBizProfile' => ['robBusinessInfo' => ['status' => 'Q']]], 'ACTIVE'],
            'non-string roc code' => ['company', ['getCompProfile' => ['rocCompanyInfo' => ['statusOfCompany' => 5]]], 'EXISTING'],
        ];
    }

    #[DataProvider('fallbacks')]
    public function test_falls_back_when_profile_has_no_usable_status(string $entityType, ?array $profile, string $expected): void
    {
        $this->assertSame($expected, SsmEntityStatus::status([], $entityType, $profile, $this->now));
    }

    public function test_meta_status_override_wins(): void
    {
        $meta = ['status' => 'REMOVED'];

        $this->assertSame('REMOVED', SsmEntityStatus::status($meta, 'company', $this->companyProfile('E'), $this->now));
    }

    public function test_blank_meta_status_is_ignored(): void
    {
        $meta = ['status' => '  '];

        $this->assertSame('DISSOLVED', SsmEntityStatus::status($meta, 'company', $this->companyProfile('D'), $this->now));
    }

    public function test_old_reg_no_per_entity_type(): void
    {
        $company = ['getCompProfile' => ['rocCompanyInfo' => ['companyNo' => '1333584', 'checkDigit' => 'H']]];
        $business = ['getBizProfile' => ['robBusinessInfo' => ['registrationNo' => 'TR0274893', 'checkDigit' => 'W']]];
        $llp = ['getLlpCurrentProfile' => ['llpCurrentProfile' => ['llpBasicProfile' => ['entityNo' => 'LLP0007828-LGN']]]];

        $this->assertSame('1333584-H', SsmEntityStatus::oldRegNo('company', $company));
        $this->assertSame('TR0274893-W', SsmEntityStatus::oldRegNo('business', $business));
        $this->assertSame('LLP0007828-LGN', SsmEntityStatus::oldRegNo('llp', $llp));
    }

    public function test_old_reg_no_is_empty_without_usable_profile(): void
    {
        $this->assertSame('', SsmEntityStatus::oldRegNo('company', null));
        $this->assertSame('', SsmEntityStatus::oldRegNo('company', ['getCompProfile' => ['rocCompanyInfo' => ['companyNo' => '', 'checkDigit' => 'H']]]));
        $this->assertSame('1333584', SsmEntityStatus::oldRegNo('company', ['getCompProfile' => ['rocCompanyInfo' => ['companyNo' => '1333584', 'checkDigit' => '']]]));
        $this->assertSame('', SsmEntityStatus::oldRegNo('llp', ['getLlpCurrentProfile' => []]));
    }

    public function test_entity_type_labels(): void
    {
        $this->assertSame('Company', SsmEntityStatus::entityTypeLabel('company'));
        $this->assertSame('Business', SsmEntityStatus::entityTypeLabel('business'));
        $this->assertSame('Limited Liability Partnerships', SsmEntityStatus::entityTypeLabel('llp'));
    }

    private function companyProfile(string $code): array
    {
        return ['getCompProfile' => ['rocCompanyInfo' => ['statusOfCompany' => $code, 'companyStatus' => 'R']]];
    }

    private function businessProfile(string $code, ?string $endBusinessDate): array
    {
        $info = ['status' => $code];

        if ($endBusinessDate !== null) {
            $info['endBusinessDate'] = $endBusinessDate;
        }

        return ['getBizProfile' => ['robBusinessInfo' => $info]];
    }

    private function llpProfile(string $code, ?string $description): array
    {
        return ['getLlpCurrentProfile' => ['llpCurrentProfile' => ['llpBasicProfile' => [
            'entityStatus' => $code,
            'entityStatusDesc' => $description,
        ]]]];
    }
}
