<?php

namespace Tests\Unit;

use App\Models\ApiClient;
use App\Models\SsmGatewayOrder;
use App\Services\SsmGateway\CartItemBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SsmGatewayCartItemBuilderTest extends TestCase
{
    use RefreshDatabase;

    private CartItemBuilder $builder;

    private ApiClient $client;

    private string $inv;

    protected function setUp(): void
    {
        parent::setUp();

        $cases = sys_get_temp_dir().'/ssm-gateway-builder-cases-'.uniqid();
        mkdir($cases.'/infomina-berhad', 0777, true);
        file_put_contents($cases.'/infomina-berhad/meta.json', json_encode([
            'regNo' => '200701018579', 'companyName' => 'INFOMINA BERHAD', 'entityType' => 'company',
        ]));
        file_put_contents($cases.'/infomina-berhad/report.pdf', '%PDF-1.4 fake');
        mkdir($cases.'/no-pdf-sdn-bhd', 0777, true);
        file_put_contents($cases.'/no-pdf-sdn-bhd/meta.json', json_encode([
            'regNo' => '200001000001', 'companyName' => 'NO PDF SDN BHD', 'entityType' => 'company',
        ]));
        config(['ssm_mock.cases_path' => $cases]);

        $this->inv = sys_get_temp_dir().'/ssm-gateway-builder-inv-'.uniqid();
        mkdir($this->inv.'/900101015555', 0777, true);
        file_put_contents($this->inv.'/900101015555/meta.json', json_encode([
            'idType' => 'MK', 'idValue' => '900101015555', 'name' => 'AHMAD BIN ALI',
        ]));
        file_put_contents($this->inv.'/900101015555/director-plain-en.pdf', '%PDF-1.4 fake');
        config(['ssm_gateway_mock.involvements_path' => $this->inv]);

        [$this->client] = ApiClient::createWithSecret('t', null, 'ssm_gateway');
        $this->builder = app(CartItemBuilder::class);
    }

    private function companyOrder(): SsmGatewayOrder
    {
        return SsmGatewayOrder::create([
            'api_client_id' => $this->client->id, 'kind' => 'company', 'subject_key' => 'infomina-berhad',
            'reg_no' => '200701018579', 'name' => 'INFOMINA BERHAD', 'variant_id' => 160,
        ]);
    }

    private function involvementOrder(int $variantId = 1047, string $subject = '900101015555'): SsmGatewayOrder
    {
        return SsmGatewayOrder::create([
            'api_client_id' => $this->client->id, 'kind' => 'involvement', 'subject_key' => $subject,
            'reg_no' => $subject, 'name' => 'AHMAD BIN ALI', 'variant_id' => $variantId,
        ]);
    }

    public function test_company_cart_item(): void
    {
        $order = $this->companyOrder();
        $item = $this->builder->cartItem($order);

        $this->assertSame('infomina-berhad', $item['com_slug']);
        $this->assertSame('INFOMINA BERHAD', $item['com_name']);
        $this->assertSame('200701018579', $item['com_reg_no']);
        $this->assertSame('200701018579', $item['com_reg_no_old']);
        $this->assertNull($item['relationships']);
        $this->assertSame('ROC', $item['entity_type']);
        $this->assertSame((string) (int) sprintf('%u', crc32('infomina-berhad')), $item['com_id']);

        $child = $item['children'][0];
        $this->assertSame($order->id, $child['cart_id']);
        $this->assertSame(160, $child['variant_id']);
        $this->assertSame('Company Profile', $child['product_name']);
        $this->assertSame(1, $child['is_ctc']);
        $this->assertSame('en', $child['lang']);
        $this->assertSame(17.9, $child['amount']);
        $this->assertStringEndsWith('/reports/infomina-berhad.pdf', $child['download_url']);
        $this->assertNull($child['gen_error']);
        $this->assertSame(0.9, $child['amountDetail']['sst']['amount']);
    }

    public function test_company_without_report_pdf_flags_gen_error(): void
    {
        $order = SsmGatewayOrder::create([
            'api_client_id' => $this->client->id, 'kind' => 'company', 'subject_key' => 'no-pdf-sdn-bhd',
            'reg_no' => '200001000001', 'name' => 'NO PDF SDN BHD', 'variant_id' => 160,
        ]);

        $child = $this->builder->cartItem($order)['children'][0];

        $this->assertNull($child['download_url']);
        $this->assertSame('Mock fixture has no report.pdf', $child['gen_error']);
        $this->assertNull($this->builder->downloadUrl($order));
    }

    public function test_involvement_cart_item(): void
    {
        $item = $this->builder->cartItem($this->involvementOrder());

        $this->assertSame('PI-900101015555', $item['com_id']);
        $this->assertNull($item['com_slug']);
        $this->assertNull($item['entity_type']);
        $this->assertSame('900101015555', $item['com_reg_no']);
        $this->assertSame('MK', $item['relationships']['personal_involvement']['id_type']);
        $this->assertSame('PI-900101015555', $item['relationships']['personal_involvement']['selection_ref_no']);
        $this->assertSame('AHMAD BIN ALI', $item['relationships']['personal_involvement']['label']);
        $this->assertStringEndsWith('/ssm-gateway/involvement-reports/900101015555/director-plain-en.pdf', $item['children'][0]['download_url']);
        $this->assertNull($item['children'][0]['gen_error']);
        $this->assertSame('Director Involvement', $item['children'][0]['product_name']);
    }

    public function test_involvement_missing_exact_variant_flags_gen_error(): void
    {
        $child = $this->builder->cartItem($this->involvementOrder(1049))['children'][0];

        $this->assertNull($child['download_url']);
        $this->assertSame('Mock fixture has no director-plain-my.pdf', $child['gen_error']);
    }

    public function test_involvement_unsupported_variant_flags_gen_error(): void
    {
        $child = $this->builder->cartItem($this->involvementOrder(1025))['children'][0];

        $this->assertNull($child['download_url']);
        $this->assertSame('Mock fixture has no sample for involvement variant 1025', $child['gen_error']);
    }

    public function test_involvement_unavailable_source_flags_gen_error(): void
    {
        $order = $this->involvementOrder(1047, '000000000009');
        $child = $this->builder->cartItem($order)['children'][0];

        $this->assertNull($child['download_url']);
        $this->assertSame('Mock fixture has no involvement source for 000000000009', $child['gen_error']);
    }

    public function test_involvement_default_fallback_keeps_submitted_identity(): void
    {
        file_put_contents($this->inv.'/default.json', json_encode(['idValue' => '900101015555']));
        $order = $this->involvementOrder(1047, '000000000009');

        $item = $this->builder->cartItem($order);

        $this->assertSame('PI-000000000009', $item['com_id']);
        $this->assertSame('000000000009', $item['com_reg_no']);
        $this->assertSame('000000000009', $item['relationships']['personal_involvement']['id_value']);
        $this->assertSame('MK', $item['relationships']['personal_involvement']['id_type']);
        $this->assertStringEndsWith('/900101015555/director-plain-en.pdf', $item['children'][0]['download_url']);
    }

    public function test_involvement_errors_wait_for_report_delay(): void
    {
        config(['ssm_gateway_mock.report_delay_seconds' => 60]);
        Carbon::setTestNow('2026-10-15 10:00:00');
        $order = $this->involvementOrder(1025);

        $child = $this->builder->cartItem($order)['children'][0];
        $this->assertNull($child['download_url']);
        $this->assertNull($child['gen_error']);

        Carbon::setTestNow('2026-10-15 10:01:00');
        $this->assertNotNull($this->builder->cartItem($order->fresh())['children'][0]['gen_error']);
        Carbon::setTestNow();
    }

    private function withCatchAll(): void
    {
        file_put_contents($this->inv.'/default.json', json_encode(['idValue' => '900101015555']));
        file_put_contents($this->inv.'/default.pdf', '%PDF-1.4 catch-all');
    }

    public function test_fallback_ic_prefers_default_ic_exact_variant_over_catch_all(): void
    {
        $this->withCatchAll();

        $url = $this->builder->cartItem($this->involvementOrder(1047, '000000000009'))['children'][0]['download_url'];

        $this->assertStringEndsWith('/900101015555/director-plain-en.pdf', $url);
    }

    public function test_fallback_ic_gets_catch_all_for_missing_variant(): void
    {
        $this->withCatchAll();

        $child = $this->builder->cartItem($this->involvementOrder(1041, '000000000009'))['children'][0];

        $this->assertStringEndsWith('/ssm-gateway/involvement-reports/default.pdf', $child['download_url']);
        $this->assertNull($child['gen_error']);
    }

    public function test_fallback_ic_gets_catch_all_for_other_involvement_products(): void
    {
        $this->withCatchAll();

        $child = $this->builder->cartItem($this->involvementOrder(1025, '000000000009'))['children'][0];

        $this->assertStringEndsWith('/ssm-gateway/involvement-reports/default.pdf', $child['download_url']);
        $this->assertNull($child['gen_error']);
    }

    public function test_default_ic_bought_directly_gets_catch_all(): void
    {
        $this->withCatchAll();

        $child = $this->builder->cartItem($this->involvementOrder(1049))['children'][0];

        $this->assertStringEndsWith('/ssm-gateway/involvement-reports/default.pdf', $child['download_url']);
    }

    public function test_own_folder_ic_never_gets_catch_all(): void
    {
        $this->withCatchAll();
        mkdir($this->inv.'/000000000008');
        file_put_contents($this->inv.'/000000000008/meta.json', json_encode(['idType' => 'MK', 'idValue' => '000000000008', 'name' => 'OWN']));
        file_put_contents($this->inv.'/000000000008/director-plain-en.pdf', '%PDF-1.4 own');

        $missing = $this->builder->cartItem($this->involvementOrder(1049, '000000000008'))['children'][0];
        $unsupported = $this->builder->cartItem($this->involvementOrder(1025, '000000000008'))['children'][0];

        $this->assertNull($missing['download_url']);
        $this->assertSame('Mock fixture has no director-plain-my.pdf', $missing['gen_error']);
        $this->assertNull($unsupported['download_url']);
        $this->assertSame('Mock fixture has no sample for involvement variant 1025', $unsupported['gen_error']);
    }

    public function test_catch_all_must_be_a_regular_file(): void
    {
        file_put_contents($this->inv.'/default.json', json_encode(['idValue' => '900101015555']));
        file_put_contents($this->inv.'/elsewhere.pdf', '%PDF-1.4 elsewhere');
        symlink($this->inv.'/elsewhere.pdf', $this->inv.'/default.pdf');

        $child = $this->builder->cartItem($this->involvementOrder(1041, '000000000009'))['children'][0];

        $this->assertNull($child['download_url']);
        $this->assertSame('Mock fixture has no secretary-ctc-en.pdf', $child['gen_error']);
    }

    public function test_download_url_respects_report_delay(): void
    {
        config(['ssm_gateway_mock.report_delay_seconds' => 60]);
        Carbon::setTestNow('2026-10-15 10:00:00');
        $order = $this->companyOrder();

        $this->assertFalse($this->builder->isReportReady($order));
        $this->assertNull($this->builder->cartItem($order)['children'][0]['download_url']);

        Carbon::setTestNow('2026-10-15 10:01:00');
        $this->assertTrue($this->builder->isReportReady($order->fresh()));
        $this->assertNotNull($this->builder->cartItem($order->fresh())['children'][0]['download_url']);

        Carbon::setTestNow();
    }

    public function test_summary_and_detail_totals(): void
    {
        $order = $this->companyOrder();
        $summary = $this->builder->cartSummary($order);

        $this->assertSame(6, $summary['sstRate']);
        $this->assertSame(15, $summary['subtotalAmount']);
        $this->assertSame(2, $summary['serviceAmount']);
        $this->assertSame(0.9, $summary['sstAmount']);
        $this->assertSame(17.9, $summary['finalAmount']);
        $this->assertSame(1, $summary['count']);
        $this->assertCount(1, $summary['items']);

        $detail = $this->builder->orderDetail($order);
        $this->assertSame($order->id, $detail['order_id']);
        $this->assertSame('SSM Price', $detail['fee_map']['price']['label']);
        $this->assertSame(0, $detail['fee_map']['ctc']['is_waived']);
    }
}
