<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSsmGatewayToken;
use Tests\TestCase;

class SsmGatewayProductTest extends TestCase
{
    use InteractsWithSsmGatewayToken;
    use RefreshDatabase;

    private string $casesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSsmGatewayToken();

        $this->casesPath = sys_get_temp_dir().'/ssm-gateway-product-'.uniqid();
        mkdir($this->casesPath.'/infomina-berhad', 0777, true);
        file_put_contents($this->casesPath.'/infomina-berhad/meta.json', json_encode([
            'regNo' => '200701018579', 'companyName' => 'INFOMINA BERHAD', 'entityType' => 'company',
        ]));
        config(['ssm_mock.cases_path' => $this->casesPath]);
    }

    public function test_product_list_returns_catalogue(): void
    {
        $response = $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/product/list', ['_user_id' => 1, 'productShelf' => true]);

        $response->assertOk();
        $this->assertIsArray($response->json('products.1'));
        $this->assertContains(160, array_merge(...array_map(
            fn ($p) => array_column($p['variants'], 'id'),
            $response->json('products.1')
        )));
    }

    public function test_product_list_by_company_requires_known_slug(): void
    {
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/product/list-by-company', ['slug' => 'infomina-berhad'])
            ->assertOk()->assertJsonStructure(['products', 'categories', 'valid_product_ids']);

        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/product/list-by-company', ['slug' => 'ghost'])
            ->assertStatus(404)->assertJsonPath('message', 'Company not found');
    }

    public function test_requires_bearer_token(): void
    {
        $this->postJson('/ssm-gateway/product/list', [])->assertStatus(401);
    }
}
