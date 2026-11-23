<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApiClients;
use Tests\TestCase;

class SearchEntityTest extends TestCase
{
    use RefreshDatabase, InteractsWithApiClients;

    private string $casesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpApiClient();

        $this->casesPath = sys_get_temp_dir().'/ssm-mock-search-test-'.uniqid();
        mkdir($this->casesPath, 0777, true);
        config(['ssm_mock.cases_path' => $this->casesPath]);

        $caseDir = $this->casesPath.'/acme-co';
        mkdir($caseDir, 0777, true);
        file_put_contents($caseDir.'/meta.json', json_encode([
            'regNo' => '123456-A',
            'companyName' => 'Acme Sdn Bhd',
            'entityType' => 'company',
        ]));
    }

    public function test_finds_case_by_reg_no(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-search-entity', ['regNo' => '123456-A', 'entityType' => 'company']);

        $response->assertOk();
        $response->assertJsonPath('getSearchEntity.searchEntity.data.0.companyName', 'Acme Sdn Bhd');
    }

    public function test_finds_case_by_partial_name(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-search-entity', ['name' => 'acme', 'entityType' => 'company']);

        $response->assertOk();
        $response->assertJsonPath('getSearchEntity.searchEntity.data.0.companyNo', '123456-A');
    }

    public function test_finds_llp_case_by_ssm_search_vocabulary(): void
    {
        $caseDir = $this->casesPath.'/teoh-plt';
        mkdir($caseDir, 0777, true);
        file_put_contents($caseDir.'/meta.json', json_encode([
            'regNo' => '201304000353',
            'companyName' => 'TEOH CONSULTANCY AND SERVICES PLT',
            'entityType' => 'llp',
        ]));

        // infominaAI-BE sends SSM's own vocabulary for LLP, not the catalogue's `llp`.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-search-entity', ['name' => 'te', 'entityType' => 'limited_liability_partnerships']);

        $response->assertOk();
        $response->assertJsonCount(1, 'getSearchEntity.searchEntity.data');
        $response->assertJsonPath('getSearchEntity.searchEntity.data.0.companyNo', '201304000353');
        $response->assertJsonPath('getSearchEntity.searchEntity.data.0.entityType', 'llp');
    }

    public function test_finds_case_by_old_reg_no_from_meta_and_returns_both_numbers(): void
    {
        $caseDir = $this->casesPath.'/kinson';
        mkdir($caseDir, 0777, true);
        file_put_contents($caseDir.'/meta.json', json_encode([
            'regNo' => '194901000013',
            'oldRegNo' => '1756-V',
            'companyName' => 'KINSON COMPANY SDN BHD',
            'entityType' => 'company',
        ]));

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-search-entity', ['regNo' => '1756-v', 'entityType' => 'company']);

        $response->assertOk();
        $response->assertJsonCount(1, 'getSearchEntity.searchEntity.data');
        $response->assertJsonPath('getSearchEntity.searchEntity.data.0.companyNo', '194901000013');
        $response->assertJsonPath('getSearchEntity.searchEntity.data.0.oldCompanyNo', '1756-V');
    }

    public function test_returns_old_reg_no_from_meta_when_searched_by_new_number(): void
    {
        $caseDir = $this->casesPath.'/kinson';
        mkdir($caseDir, 0777, true);
        file_put_contents($caseDir.'/meta.json', json_encode([
            'regNo' => '194901000013',
            'oldRegNo' => '1756-V',
            'companyName' => 'KINSON COMPANY SDN BHD',
            'entityType' => 'company',
        ]));

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-search-entity', ['regNo' => '194901000013', 'entityType' => 'company']);

        $response->assertOk();
        $response->assertJsonPath('getSearchEntity.searchEntity.data.0.oldCompanyNo', '1756-V');
    }

    public function test_finds_case_by_old_reg_no_from_profile_when_meta_has_none(): void
    {
        $caseDir = $this->casesPath.'/bestimex';
        mkdir($caseDir, 0777, true);
        file_put_contents($caseDir.'/meta.json', json_encode([
            'regNo' => '199001010842',
            'companyName' => 'BESTIMEX HOLDINGS SDN. BHD.',
            'entityType' => 'company',
        ]));
        file_put_contents($caseDir.'/company-profile.json', json_encode([
            'getCompProfile' => ['rocCompanyInfo' => ['companyNo' => '202412', 'checkDigit' => 'U']],
        ]));

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-search-entity', ['regNo' => '202412-U', 'entityType' => 'company']);

        $response->assertOk();
        $response->assertJsonPath('getSearchEntity.searchEntity.data.0.companyNo', '199001010842');
        $response->assertJsonPath('getSearchEntity.searchEntity.data.0.oldCompanyNo', '202412-U');
    }

    public function test_old_company_no_falls_back_to_reg_no_when_no_old_number_is_known(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-search-entity', ['regNo' => '123456-A', 'entityType' => 'company']);

        $response->assertOk();
        $response->assertJsonPath('getSearchEntity.searchEntity.data.0.oldCompanyNo', '123456-A');
    }

    public function test_old_reg_no_match_still_respects_entity_type(): void
    {
        $caseDir = $this->casesPath.'/kinson';
        mkdir($caseDir, 0777, true);
        file_put_contents($caseDir.'/meta.json', json_encode([
            'regNo' => '194901000013',
            'oldRegNo' => '1756-V',
            'companyName' => 'KINSON COMPANY SDN BHD',
            'entityType' => 'company',
        ]));

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-search-entity', ['regNo' => '1756-V', 'entityType' => 'business']);

        $response->assertStatus(404);
    }

    public function test_returns_404_when_no_case_matches(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-search-entity', ['regNo' => 'unknown', 'entityType' => 'company']);

        $response->assertStatus(404);
    }

    public function test_rejects_request_without_gateway_credentials(): void
    {
        $response = $this->postJson('/get-search-entity', ['regNo' => '123456-A', 'entityType' => 'company']);

        $response->assertStatus(401);
    }
}
