<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSsmGatewayToken;
use Tests\TestCase;

class SsmGatewayCompanyTest extends TestCase
{
    use InteractsWithSsmGatewayToken;
    use RefreshDatabase;

    private string $casesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSsmGatewayToken();

        $this->casesPath = sys_get_temp_dir().'/ssm-gateway-company-'.uniqid();
        mkdir($this->casesPath.'/infomina-berhad', 0777, true);
        file_put_contents($this->casesPath.'/infomina-berhad/meta.json', json_encode([
            'regNo' => '200701018579', 'companyName' => 'INFOMINA BERHAD', 'entityType' => 'company',
        ]));
        config(['ssm_mock.cases_path' => $this->casesPath]);
    }

    private function common(array $extra = []): array
    {
        return array_merge(['_user_id' => 1, '_org_id' => 10, '_org_role' => 'owner', '_role_id' => 2, '_testing_secret' => null], $extra);
    }

    public function test_search_finds_case_by_reg_no(): void
    {
        $response = $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/company/search', $this->common(['search' => '200701018579', 'type_id' => 0, 'page' => 1, 'offset' => 10, 'sort' => []]));

        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('is_more_than_limit', false);
        $response->assertJsonPath('data.0.slug', 'infomina-berhad');
        $response->assertJsonPath('data.0.name', 'INFOMINA BERHAD');
        $response->assertJsonPath('data.0.reg_no', '200701018579');
        $response->assertJsonPath('data.0.entity', 'Company');
        $this->assertIsInt($response->json('data.0.id'));
    }

    public function test_search_trims_whitespace(): void
    {
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/company/search', $this->common(['search' => '  200701018579 ']))
            ->assertOk()->assertJsonPath('total', 1);
    }

    public function test_search_unknown_returns_empty_data(): void
    {
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/company/search', $this->common(['search' => '999999']))
            ->assertOk()->assertJsonPath('total', 0)->assertJsonPath('data', []);
    }

    public function test_search_without_term_returns_empty_data(): void
    {
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/company/search', $this->common())
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_get_info_by_slug(): void
    {
        $response = $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/company/get-info', $this->common(['slug' => 'infomina-berhad']));

        $response->assertOk();
        $response->assertJsonPath('name', 'INFOMINA BERHAD');
        $response->assertJsonPath('registration_no', '200701018579');
        $response->assertJsonPath('entity_type_code', 'ROC');
        $response->assertJsonPath('is_roc', 1);
        $this->assertIsInt($response->json('id'));
    }

    public function test_get_info_unknown_slug_is_404(): void
    {
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/company/get-info', $this->common(['slug' => 'ghost']))
            ->assertStatus(404)->assertJsonPath('message', 'Company not found');
    }

    public function test_requires_bearer_token(): void
    {
        $this->postJson('/ssm-gateway/company/search', $this->common(['search' => 'x']))->assertStatus(401);
        $this->postJson('/ssm-gateway/company/get-info', $this->common(['slug' => 'x']))->assertStatus(401);
    }
}
