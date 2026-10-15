<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSsmGatewayToken;
use Tests\TestCase;

class SsmGatewayInvolvementTest extends TestCase
{
    use InteractsWithSsmGatewayToken;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSsmGatewayToken();

        $path = sys_get_temp_dir().'/ssm-gateway-inv-feature-'.uniqid();
        mkdir($path.'/900101015555', 0777, true);
        file_put_contents($path.'/900101015555/meta.json', json_encode([
            'idType' => 'MK', 'idValue' => '900101015555', 'name' => 'AHMAD BIN ALI',
        ]));
        file_put_contents($path.'/900101015555/report.pdf', '%PDF-1.4 fake');
        config(['ssm_gateway_mock.involvements_path' => $path]);
    }

    public function test_submission_known_id_returns_selection_ref(): void
    {
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/personal-involvement/submission', ['_user_id' => 1, 'idType' => 'MK', 'idValue' => '900101015555'])
            ->assertOk()
            ->assertJsonPath('data.selectionRefNo', 'PI-900101015555')
            ->assertJsonPath('message', 'Submission received');
    }

    public function test_submission_unknown_id_is_404(): void
    {
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/personal-involvement/submission', ['idType' => 'MK', 'idValue' => '000000000000'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'No involvement record found for the given ID');
    }

    public function test_submission_requires_id_value(): void
    {
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/personal-involvement/submission', ['idType' => 'MK'])
            ->assertStatus(422);
    }

    public function test_report_route_serves_pdf_without_auth(): void
    {
        $this->get('/ssm-gateway/involvement-reports/900101015555.pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get('/ssm-gateway/involvement-reports/000000000000.pdf')->assertStatus(404);
    }

    public function test_shipped_example_fixture_exists(): void
    {
        $base = storage_path('app/ssm-gateway-fixtures/involvements/900101015555');
        $this->assertFileExists($base.'/meta.json');
        $this->assertFileExists($base.'/report.pdf');
        $this->assertSame('900101015555', json_decode(file_get_contents($base.'/meta.json'), true)['idValue']);
    }
}
