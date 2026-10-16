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
