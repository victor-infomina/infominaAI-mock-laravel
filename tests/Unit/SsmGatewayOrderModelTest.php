<?php

namespace Tests\Unit;

use App\Models\ApiClient;
use App\Models\SsmGatewayOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SsmGatewayOrderModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_row_with_nullable_bill_and_ref(): void
    {
        [$client] = ApiClient::createWithSecret('t', null, 'ssm_gateway');

        $order = SsmGatewayOrder::create([
            'api_client_id' => $client->id,
            'kind' => 'company',
            'subject_key' => 'infomina-berhad',
            'reg_no' => '200701018579',
            'name' => 'INFOMINA BERHAD',
            'variant_id' => 160,
        ]);

        $this->assertNotNull($order->id);
        $this->assertNull($order->bill_id);
        $this->assertNull($order->ref_no);
        $this->assertSame(160, $order->fresh()->variant_id);
    }

    public function test_config_defaults(): void
    {
        $this->assertSame(86400, config('ssm_gateway_mock.token_ttl_seconds'));
        $this->assertSame(0, config('ssm_gateway_mock.report_delay_seconds'));
        $this->assertSame('6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI', config('ssm_gateway_mock.recaptcha_sitekey'));
        $this->assertStringEndsWith('app/ssm-gateway-fixtures/involvements', config('ssm_gateway_mock.involvements_path'));
    }
}
