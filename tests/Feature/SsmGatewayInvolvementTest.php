<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSsmGatewayToken;
use Tests\TestCase;

class SsmGatewayInvolvementTest extends TestCase
{
    use InteractsWithSsmGatewayToken;
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSsmGatewayToken();

        $this->root = sys_get_temp_dir().'/ssm-gateway-inv-feature-'.uniqid();
        $this->writeFixture('900101015555', 'AHMAD BIN ALI', ['director-plain-en']);
        config(['ssm_gateway_mock.involvements_path' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function writeFixture(string $idValue, string $name, array $identities): void
    {
        mkdir($this->root.'/'.$idValue, 0777, true);
        file_put_contents($this->root.'/'.$idValue.'/meta.json', json_encode([
            'idType' => 'MK', 'idValue' => $idValue, 'name' => $name,
        ]));
        foreach ($identities as $identity) {
            file_put_contents($this->root.'/'.$idValue.'/'.$identity.'.pdf', '%PDF-1.4 '.$idValue.' '.$identity);
        }
    }

    private function setDefault(string $idValue): void
    {
        file_put_contents($this->root.'/default.json', json_encode(['idValue' => $idValue]));
    }

    private function buy(string $idValue, int $variantId)
    {
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/personal-involvement/submission', ['idValue' => $idValue])
            ->assertOk()->assertJsonPath('data.selectionRefNo', 'PI-'.$idValue);

        return $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/cart/add-item', [
                'company_id' => 'PI-'.$idValue, 'personal_involvement' => true, 'variant_id' => $variantId,
            ])->assertOk();
    }

    private function pdfBody(string $url): string
    {
        return file_get_contents($this->get($url)->assertOk()->baseResponse->getFile()->getPathname());
    }

    public static function stagingVariants(): array
    {
        return [
            [1048, 'director-ctc-en'], [1050, 'director-ctc-my'], [1047, 'director-plain-en'], [1049, 'director-plain-my'],
            [1045, 'shareholder-ctc-en'], [1043, 'shareholder-ctc-my'], [1046, 'shareholder-plain-en'], [1044, 'shareholder-plain-my'],
            [1041, 'secretary-ctc-en'], [1039, 'secretary-ctc-my'], [1042, 'secretary-plain-en'], [1040, 'secretary-plain-my'],
        ];
    }

    public function test_submission_known_id_returns_selection_ref(): void
    {
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/personal-involvement/submission', ['_user_id' => 1, 'idType' => 'MK', 'idValue' => '900101015555'])
            ->assertOk()
            ->assertJsonPath('data.selectionRefNo', 'PI-900101015555')
            ->assertJsonPath('message', 'Submission received');
    }

    public function test_submission_unknown_id_without_default_is_404(): void
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

    public function test_default_preserves_submitted_identity_in_cart(): void
    {
        $this->setDefault('900101015555');

        $response = $this->buy('000000000001', 1047)
            ->assertJsonPath('cart.relationships.personal_involvement.id_value', '000000000001')
            ->assertJsonPath('cart.relationships.personal_involvement.selection_ref_no', 'PI-000000000001');

        $this->assertDatabaseHas('ssm_gateway_order', [
            'id' => $response->json('cart_id'), 'subject_key' => '000000000001', 'reg_no' => '000000000001',
        ]);
        $this->assertStringEndsWith('/900101015555/director-plain-en.pdf', $response->json('cart.children.0.download_url'));
    }

    public function test_own_folder_takes_precedence_over_default(): void
    {
        $this->writeFixture('000000000002', 'OWN PERSON', ['director-plain-en']);
        $this->setDefault('900101015555');

        $url = $this->buy('000000000002', 1047)->json('cart.children.0.download_url');

        $this->assertStringEndsWith('/000000000002/director-plain-en.pdf', $url);
        $this->assertSame('%PDF-1.4 000000000002 director-plain-en', $this->pdfBody($url));
    }

    public function test_incomplete_own_folder_does_not_borrow_from_complete_default(): void
    {
        $this->writeFixture('000000000003', 'OWN PERSON', ['secretary-plain-my']);
        $this->writeFixture('888888888888', 'DEFAULT PERSON', array_column(self::stagingVariants(), 1));
        $this->setDefault('888888888888');

        $child = $this->buy('000000000003', 1047)->json('cart.children.0');

        $this->assertNull($child['download_url']);
        $this->assertSame('Mock fixture has no director-plain-en.pdf', $child['gen_error']);
    }

    public function test_corrupt_own_folder_does_not_fall_back(): void
    {
        mkdir($this->root.'/000000000004');
        file_put_contents($this->root.'/000000000004/meta.json', '{broken');
        $this->setDefault('900101015555');

        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/personal-involvement/submission', ['idValue' => '000000000004'])
            ->assertStatus(404);
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/cart/add-item', ['company_id' => 'PI-000000000004', 'personal_involvement' => true, 'variant_id' => 1047])
            ->assertStatus(404);
    }

    public function test_invalid_or_missing_default_is_unavailable(): void
    {
        file_put_contents($this->root.'/default.json', '{broken');
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/personal-involvement/submission', ['idValue' => '000000000005'])
            ->assertStatus(404);

        $this->setDefault('777777777777');
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/personal-involvement/submission', ['idValue' => '000000000005'])
            ->assertStatus(404);
    }

    public function test_unsupported_involvement_variant_flags_gen_error(): void
    {
        $child = $this->buy('900101015555', 1025)->json('cart.children.0');

        $this->assertNull($child['download_url']);
        $this->assertSame('Mock fixture has no sample for involvement variant 1025', $child['gen_error']);
    }

    #[DataProvider('stagingVariants')]
    public function test_each_staging_variant_serves_its_own_sample(int $variantId, string $identity): void
    {
        File::deleteDirectory($this->root.'/900101015555');
        $this->writeFixture('900101015555', 'AHMAD BIN ALI', array_column(self::stagingVariants(), 1));

        $url = $this->buy('900101015555', $variantId)->json('cart.children.0.download_url');

        $this->assertStringEndsWith('/900101015555/'.$identity.'.pdf', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-1.4 900101015555 '.$identity, $this->pdfBody($url));
    }

    public function test_returned_url_keeps_its_source_after_default_changes(): void
    {
        $this->writeFixture('888888888888', 'OTHER DEFAULT', ['director-plain-en']);
        $this->setDefault('900101015555');
        $url = $this->buy('000000000006', 1047)->json('cart.children.0.download_url');

        $this->setDefault('888888888888');

        $this->assertSame('%PDF-1.4 900101015555 director-plain-en', $this->pdfBody($url));
    }

    public function test_report_route_serves_exact_pdf_without_auth(): void
    {
        $this->get('/ssm-gateway/involvement-reports/900101015555/director-plain-en.pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get('/ssm-gateway/involvement-reports/900101015555/director-plain-my.pdf')->assertStatus(404);
        $this->get('/ssm-gateway/involvement-reports/000000000000/director-plain-en.pdf')->assertStatus(404);
        $this->get('/ssm-gateway/involvement-reports/900101015555/report.pdf')->assertStatus(404);
    }

    public function test_report_route_does_not_use_default(): void
    {
        $this->setDefault('900101015555');

        $this->get('/ssm-gateway/involvement-reports/000000000007/director-plain-en.pdf')->assertStatus(404);
    }

    public function test_legacy_single_report_url_is_gone(): void
    {
        file_put_contents($this->root.'/900101015555/report.pdf', '%PDF-1.4 legacy');

        $this->get('/ssm-gateway/involvement-reports/900101015555.pdf')->assertStatus(404);
    }

    public function test_catch_all_route_serves_default_pdf_without_auth(): void
    {
        $this->get('/ssm-gateway/involvement-reports/default.pdf')->assertStatus(404);

        file_put_contents($this->root.'/default.pdf', '%PDF-1.4 catch-all');

        $this->assertSame('%PDF-1.4 catch-all', $this->pdfBody('/ssm-gateway/involvement-reports/default.pdf'));
        $this->get('/ssm-gateway/involvement-reports/default.pdf')->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_unknown_ic_buys_any_variant_through_catch_all(): void
    {
        $this->setDefault('900101015555');
        file_put_contents($this->root.'/default.pdf', '%PDF-1.4 catch-all');

        $url = $this->buy('000000000010', 1039)->assertJsonPath('cart.children.0.gen_error', null)->json('cart.children.0.download_url');

        $this->assertSame('%PDF-1.4 catch-all', $this->pdfBody($url));
    }

    public function test_shipped_example_fixture_is_the_default(): void
    {
        $root = storage_path('app/ssm-gateway-fixtures/involvements');
        $this->assertFileExists($root.'/900101015555/meta.json');
        $this->assertFileExists($root.'/900101015555/director-plain-en.pdf');
        $this->assertFileDoesNotExist($root.'/900101015555/report.pdf');
        $this->assertSame('900101015555', json_decode(file_get_contents($root.'/900101015555/meta.json'), true)['idValue']);
        $this->assertSame(['idValue' => '900101015555'], json_decode(file_get_contents($root.'/default.json'), true));
    }
}
