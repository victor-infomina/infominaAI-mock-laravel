<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithApiClients;
use Tests\TestCase;

/**
 * AE-522: POST /get-status-entity, shaped like the CIDP responses captured from prod
 * (docs/sample-ssm/status-entity/ in the workspace).
 */
class StatusEntityTest extends TestCase
{
    use RefreshDatabase, InteractsWithApiClients;

    private string $casesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpApiClient();

        $this->casesPath = sys_get_temp_dir().'/ssm-mock-status-test-'.uniqid();
        mkdir($this->casesPath, 0777, true);
        config(['ssm_mock.cases_path' => $this->casesPath]);

        $this->makeCase('toyyibpay', [
            'regNo' => '201901024255',
            'companyName' => 'TOYYIBPAY SDN. BHD.',
            'entityType' => 'company',
        ], 'company-profile.json', ['getCompProfile' => ['rocCompanyInfo' => [
            'companyNo' => '1333584', 'checkDigit' => 'H', 'statusOfCompany' => 'E', 'companyStatus' => 'R',
        ]]]);

        $this->makeCase('pelangi', [
            'regNo' => '198701005805',
            'companyName' => 'PELANGI AIRWAYS SDN. BHD.',
            'entityType' => 'company',
        ], 'company-profile.json', ['getCompProfile' => ['rocCompanyInfo' => [
            'companyNo' => '164476', 'checkDigit' => 'M', 'statusOfCompany' => 'W', 'companyStatus' => 'R',
        ]]]);

        $this->makeCase('kelana-rimba', [
            'regNo' => '202203288787',
            'companyName' => 'KELANA RIMBA ENTERPRISE',
            'entityType' => 'business',
        ], 'business-profile.json', ['getBizProfile' => ['robBusinessInfo' => [
            'registrationNo' => 'TR0274893', 'checkDigit' => 'W', 'status' => 'A',
            'endBusinessDate' => '2026-10-12T16:00:00.000Z',
        ]]]);

        $this->makeCase('binrafi', [
            'regNo' => '201604001116',
            'companyName' => 'BINRAFI OPTICAL PLT',
            'entityType' => 'llp',
        ], 'llp-profile.json', ['getLlpCurrentProfile' => ['llpCurrentProfile' => ['llpBasicProfile' => [
            'entityNo' => 'LLP0007828-LGN', 'entityNoNewFormat' => '201604001116',
            'entityStatus' => '5', 'entityStatusDesc' => 'WINDING UP BY COURT',
        ]]]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->deleteDirectory($this->casesPath);
        parent::tearDown();
    }

    public function test_returns_the_prod_response_shape_for_a_company(): void
    {
        $response = $this->postStatus(['regNo' => '201901024255', 'entityType' => 'company']);

        $response->assertOk();
        $response->assertExactJson([
            'getStatusEntity' => [
                'clientRefNo' => '',
                'requestRefNo' => 'toyyibpay',
                'statusEntity' => [
                    'data' => [
                        'companyName' => 'TOYYIBPAY SDN. BHD.',
                        'companyNo' => '201901024255',
                        'oldCompanyNo' => '1333584-H',
                        'entityType' => 'Company',
                        'companyStatus' => 'EXISTING',
                    ],
                ],
            ],
        ]);
    }

    public function test_maps_roc_status_code(): void
    {
        $this->postStatus(['regNo' => '198701005805'])
            ->assertOk()
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyStatus', 'WINDING UP');
    }

    public function test_active_business_past_its_end_date_is_expired(): void
    {
        Carbon::setTestNow('2026-11-16T07:00:00Z');

        $this->postStatus(['regNo' => '202203288787', 'entityType' => 'business'])
            ->assertOk()
            ->assertJsonPath('getStatusEntity.statusEntity.data.entityType', 'Business')
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyStatus', 'EXPIRED');
    }

    public function test_active_business_before_its_end_date_is_active(): void
    {
        Carbon::setTestNow('2026-10-12T15:59:59Z');

        $this->postStatus(['regNo' => '202203288787'])
            ->assertOk()
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyStatus', 'ACTIVE');
    }

    public function test_llp_uses_profile_description_and_label(): void
    {
        $this->postStatus(['regNo' => '201604001116', 'entityType' => 'limited_liability_partnerships'])
            ->assertOk()
            ->assertJsonPath('getStatusEntity.statusEntity.data.entityType', 'Limited Liability Partnerships')
            ->assertJsonPath('getStatusEntity.statusEntity.data.oldCompanyNo', 'LLP0007828-LGN')
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyStatus', 'WINDING UP BY COURT');
    }

    public function test_llp_with_empty_description_is_existing(): void
    {
        $this->makeCase('jcw', [
            'regNo' => '202404002467',
            'companyName' => 'JCW PROPERTIES PLT',
            'entityType' => 'llp',
        ], 'llp-profile.json', ['getLlpCurrentProfile' => ['llpCurrentProfile' => ['llpBasicProfile' => [
            'entityNo' => 'LLP0039967-LGN', 'entityStatus' => '', 'entityStatusDesc' => '',
        ]]]]);

        $this->postStatus(['regNo' => '202404002467'])
            ->assertOk()
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyStatus', 'EXISTING');
    }

    public function test_finds_case_by_old_reg_no_and_returns_canonical_numbers(): void
    {
        foreach (['1333584-H' => '201901024255', 'tr0274893-w' => '202203288787', 'LLP0007828-LGN' => '201604001116'] as $old => $new) {
            $this->postStatus(['regNo' => $old])
                ->assertOk()
                ->assertJsonPath('getStatusEntity.statusEntity.data.companyNo', $new);
        }
    }

    public function test_reg_no_match_is_case_insensitive_and_trimmed(): void
    {
        $this->makeCase('example', [
            'regNo' => '000000-X',
            'companyName' => 'Example Company Sdn Bhd',
            'entityType' => 'company',
        ]);

        $this->postStatus(['regNo' => ' 000000-x '])
            ->assertOk()
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyNo', '000000-X');
    }

    public function test_case_without_profile_falls_back_with_empty_old_number(): void
    {
        $this->makeCase('example', [
            'regNo' => '000000-X',
            'companyName' => 'Example Company Sdn Bhd',
            'entityType' => 'company',
        ]);

        $this->postStatus(['regNo' => '000000-X'])
            ->assertOk()
            ->assertJsonPath('getStatusEntity.statusEntity.data.oldCompanyNo', '')
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyStatus', 'EXISTING');
    }

    public function test_meta_old_reg_no_is_matched_and_returned_without_a_profile(): void
    {
        $this->makeCase('kinson', [
            'regNo' => '194901000013',
            'oldRegNo' => '1756-V',
            'companyName' => 'KINSON COMPANY SDN BHD',
            'entityType' => 'company',
        ]);

        $this->postStatus(['regNo' => '1756-V'])
            ->assertOk()
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyNo', '194901000013')
            ->assertJsonPath('getStatusEntity.statusEntity.data.oldCompanyNo', '1756-V');
    }

    public function test_meta_status_override_wins(): void
    {
        $this->makeCase('removed-co', [
            'regNo' => '199901000001',
            'companyName' => 'REMOVED CO SDN. BHD.',
            'entityType' => 'company',
            'status' => 'REMOVED',
        ], 'company-profile.json', ['getCompProfile' => ['rocCompanyInfo' => ['statusOfCompany' => 'E']]]);

        $this->postStatus(['regNo' => '199901000001'])
            ->assertOk()
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyStatus', 'REMOVED');
    }

    public function test_entity_type_narrows_the_match(): void
    {
        $this->postStatus(['regNo' => '201901024255', 'entityType' => 'business'])
            ->assertNotFound()
            ->assertExactJson(['error' => 'not_found']);
    }

    public function test_empty_entity_type_is_treated_as_absent(): void
    {
        $this->postStatus(['regNo' => '201901024255', 'entityType' => ''])->assertOk();
    }

    public function test_echoes_client_ref_no_header(): void
    {
        $this->withHeaders($this->authHeaders() + ['x-Client-Ref-No' => 'REF-123'])
            ->postJson('/get-status-entity', ['regNo' => '201901024255'])
            ->assertOk()
            ->assertJsonPath('getStatusEntity.clientRefNo', 'REF-123');
    }

    public function test_returns_only_string_values(): void
    {
        $data = $this->postStatus(['regNo' => '201604001116'])->json('getStatusEntity.statusEntity.data');

        $this->assertSame(['companyName', 'companyNo', 'oldCompanyNo', 'entityType', 'companyStatus'], array_keys($data));
        $this->assertContainsOnly('string', $data);
    }

    public function test_serves_meta_identity_even_when_profile_is_a_different_entity(): void
    {
        // Intentional in this test environment: meta.json is the identity of record, so a case may
        // deliberately serve another entity's profile under its regNo (avoids leaking real data).
        $this->makeCase('teoh', [
            'regNo' => '201304000353',
            'companyName' => 'TEOH CONSULTANCY AND SERVICES PLT',
            'entityType' => 'llp',
        ], 'llp-profile.json', ['getLlpCurrentProfile' => ['llpCurrentProfile' => ['llpBasicProfile' => [
            'entityNo' => 'LLP0000333-LGN', 'entityNoNewFormat' => '201304000338',
            'entityStatus' => '8', 'entityStatusDesc' => 'DISSOLVED',
        ]]]]);

        $this->postStatus(['regNo' => '201304000353'])
            ->assertOk()
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyName', 'TEOH CONSULTANCY AND SERVICES PLT')
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyNo', '201304000353')
            ->assertJsonPath('getStatusEntity.statusEntity.data.companyStatus', 'DISSOLVED');
    }

    public function test_not_found_cases(): void
    {
        foreach ([
            ['regNo' => '999999999999'],
            [],
            ['regNo' => ''],
            ['regNo' => '   '],
            ['regNo' => ['201901024255']],
            ['regNo' => '201901024255', 'entityType' => 'audit_firm'],
            ['regNo' => '201901024255', 'entityType' => 'llp'],
        ] as $payload) {
            $this->postStatus($payload)
                ->assertNotFound()
                ->assertExactJson(['error' => 'not_found']);
        }
    }

    public function test_requires_gateway_credentials(): void
    {
        $this->postJson('/get-status-entity', ['regNo' => '201901024255'])
            ->assertUnauthorized();
    }

    private function postStatus(array $payload)
    {
        return $this->withHeaders($this->authHeaders())->postJson('/get-status-entity', $payload);
    }

    private function makeCase(string $key, array $meta, ?string $profileFile = null, ?array $profile = null): void
    {
        $dir = $this->casesPath.'/'.$key;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/meta.json', json_encode($meta));

        if ($profileFile !== null) {
            file_put_contents($dir.'/'.$profileFile, json_encode($profile));
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $full = $path.'/'.$entry;
            is_dir($full) ? $this->deleteDirectory($full) : unlink($full);
        }

        rmdir($path);
    }
}
