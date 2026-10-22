<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\SsmGatewayOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSsmGatewayToken;
use Tests\TestCase;

class SsmGatewayOrderTest extends TestCase
{
    use InteractsWithSsmGatewayToken;
    use RefreshDatabase;

    private int $companyId;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSsmGatewayToken();

        $cases = sys_get_temp_dir().'/ssm-gateway-order-cases-'.uniqid();
        mkdir($cases.'/infomina-berhad', 0777, true);
        file_put_contents($cases.'/infomina-berhad/meta.json', json_encode([
            'regNo' => '200701018579', 'companyName' => 'INFOMINA BERHAD', 'entityType' => 'company',
        ]));
        file_put_contents($cases.'/infomina-berhad/report.pdf', '%PDF-1.4 fake');
        config(['ssm_mock.cases_path' => $cases]);

        $inv = sys_get_temp_dir().'/ssm-gateway-order-inv-'.uniqid();
        mkdir($inv.'/900101015555', 0777, true);
        file_put_contents($inv.'/900101015555/meta.json', json_encode([
            'idType' => 'MK', 'idValue' => '900101015555', 'name' => 'AHMAD BIN ALI',
        ]));
        file_put_contents($inv.'/900101015555/director-plain-en.pdf', '%PDF-1.4 fake');
        config(['ssm_gateway_mock.involvements_path' => $inv]);

        $this->companyId = (int) sprintf('%u', crc32('infomina-berhad'));
    }

    private function gatewayPost(string $path, array $body)
    {
        return $this->withHeaders($this->bearerHeaders())->postJson('/ssm-gateway'.$path, array_merge(
            ['_user_id' => 1, '_org_id' => 10, '_org_role' => 'owner', '_role_id' => 2, '_testing_secret' => null],
            $body
        ));
    }

    private function addCompanyItem(): int
    {
        return $this->gatewayPost('/cart/add-item', ['variant_id' => 160, 'company_id' => (string) $this->companyId])->json('cart_id');
    }

    public function test_add_item_creates_order_and_returns_cart(): void
    {
        $response = $this->gatewayPost('/cart/add-item', ['variant_id' => 160, 'company_id' => $this->companyId]);

        $response->assertOk();
        $response->assertJsonPath('isOk', true);
        $cartId = $response->json('cart_id');
        $this->assertIsInt($cartId);
        $response->assertJsonPath('cart.com_reg_no', '200701018579');
        $response->assertJsonPath('cart.children.0.cart_id', $cartId);
        $response->assertJsonPath('cart.children.0.variant_id', 160);

        $this->assertDatabaseHas('ssm_gateway_order', [
            'id' => $cartId, 'api_client_id' => $this->ssmGatewayClient->id, 'kind' => 'company',
            'subject_key' => 'infomina-berhad', 'variant_id' => 160, 'bill_id' => null, 'ref_no' => null,
        ]);
    }

    public function test_add_item_accepts_company_id_as_string(): void
    {
        $this->gatewayPost('/cart/add-item', ['variant_id' => 158, 'company_id' => (string) $this->companyId])
            ->assertOk()->assertJsonPath('isOk', true);
    }

    public function test_add_item_unknown_company_is_404(): void
    {
        $this->gatewayPost('/cart/add-item', ['variant_id' => 160, 'company_id' => '12345'])
            ->assertStatus(404)->assertJsonPath('message', 'Company not found');
    }

    public function test_add_item_unknown_variant_is_422(): void
    {
        $this->gatewayPost('/cart/add-item', ['variant_id' => 999999, 'company_id' => $this->companyId])
            ->assertStatus(422)->assertJsonPath('message', 'Invalid variant');
        $this->gatewayPost('/cart/add-item', ['company_id' => $this->companyId])->assertStatus(422);
    }

    public function test_add_item_personal_involvement(): void
    {
        $response = $this->gatewayPost('/cart/add-item', ['variant_id' => 1047, 'company_id' => 'PI-900101015555', 'personal_involvement' => true]);

        $response->assertOk();
        $response->assertJsonPath('cart.com_id', 'PI-900101015555');
        $response->assertJsonPath('cart.com_reg_no', '900101015555');
        $response->assertJsonPath('cart.relationships.personal_involvement.id_value', '900101015555');
        $this->assertDatabaseHas('ssm_gateway_order', ['id' => $response->json('cart_id'), 'kind' => 'involvement', 'subject_key' => '900101015555']);
    }

    public function test_add_item_personal_involvement_bad_ref_is_404(): void
    {
        $this->gatewayPost('/cart/add-item', ['variant_id' => 1047, 'company_id' => 'PI-000000000000', 'personal_involvement' => true])
            ->assertStatus(404)->assertJsonPath('message', 'Invalid selection reference');
        $this->gatewayPost('/cart/add-item', ['variant_id' => 1047, 'company_id' => '900101015555', 'personal_involvement' => true])
            ->assertStatus(404);
    }

    public function test_checkout_returns_cart_and_address(): void
    {
        $cartId = $this->addCompanyItem();

        $response = $this->gatewayPost('/order/init-checkout-page', ['cart_ids' => [$cartId]]);

        $response->assertOk();
        $response->assertJsonPath('is_email_verified', true);
        $response->assertJsonPath('cart.count', 1);
        $response->assertJsonPath('cart.finalAmount', 17.9);
        $response->assertJsonPath('cart.items.0.children.0.cart_id', $cartId);
        $response->assertJsonPath('address.email', $this->ssmGatewayClient->key);
        $response->assertJsonPath('address.country_code', 'MAL');
        $response->assertJsonPath('address.state_code', 'W');
        $response->assertJsonPath('address.address2', null);
        $response->assertJsonStructure(['address' => ['full_name', 'phone', 'email', 'address1', 'address2', 'city', 'state_code', 'zipcode', 'country_code', 'country_label', 'state_label']]);
    }

    public function test_checkout_requires_cart_ids(): void
    {
        $this->gatewayPost('/order/init-checkout-page', [])->assertStatus(422);
        $this->gatewayPost('/order/init-checkout-page', ['cart_ids' => []])->assertStatus(422);
        $this->gatewayPost('/payment/ssm-product', [])->assertStatus(422);
    }

    public function test_checkout_unknown_cart_is_404(): void
    {
        $this->gatewayPost('/order/init-checkout-page', ['cart_ids' => [999]])
            ->assertStatus(404)->assertJsonPath('message', 'Cart not found');
    }

    public function test_payment_assigns_bill_id_once(): void
    {
        $cartId = $this->addCompanyItem();
        $body = ['cart_ids' => [$cartId], 'is_use_point' => false, 'is_use_pay_later' => true, 'is_foc' => false,
            'full_name' => 'SSM Mock', 'phone' => '0123456789', 'email' => 'x@y.z', 'address1' => 'a', 'address2' => null,
            'city' => 'KL', 'state_code' => 'W', 'zipcode' => '50000', 'country_code' => 'MAL'];

        $first = $this->gatewayPost('/payment/ssm-product', $body);
        $first->assertOk();
        $first->assertJsonPath('is_skip_billplz', true);
        $first->assertJsonStructure(['skip_billplz_redirect_url', 'is_skip_billplz', 'bill_id', 'bill_url']);
        $billId = $first->json('bill_id');
        $this->assertStringStartsWith('mockbill', $billId);

        $this->gatewayPost('/payment/ssm-product', $body)->assertJsonPath('bill_id', $billId);
        $this->assertDatabaseHas('ssm_gateway_order', ['id' => $cartId, 'bill_id' => $billId]);
    }

    public function test_thank_you_assigns_ref_no_once(): void
    {
        $cartId = $this->addCompanyItem();
        $billId = $this->gatewayPost('/payment/ssm-product', ['cart_ids' => [$cartId]])->json('bill_id');

        $first = $this->gatewayPost('/order/init-thank-you-page', ['billplz_id' => $billId]);
        $first->assertOk();
        $first->assertJsonPath('order_id', $cartId);
        $first->assertJsonPath('is_paid', 1);
        $first->assertJsonPath('detail.order_id', $cartId);
        $refNo = $first->json('ref_no');
        $this->assertStringStartsWith('ORDMOCK', $refNo);

        $this->gatewayPost('/order/init-thank-you-page', ['billplz_id' => $billId])->assertJsonPath('ref_no', $refNo);
        $this->gatewayPost('/order/init-thank-you-page', ['billplz_id' => 'nope'])->assertStatus(404)->assertJsonPath('message', 'Order not found');
        $this->gatewayPost('/order/init-thank-you-page', [])->assertStatus(422);
    }

    public function test_init_order_page_reports_download_url(): void
    {
        $cartId = $this->addCompanyItem();
        $billId = $this->gatewayPost('/payment/ssm-product', ['cart_ids' => [$cartId]])->json('bill_id');
        $refNo = $this->gatewayPost('/order/init-thank-you-page', ['billplz_id' => $billId])->json('ref_no');

        $response = $this->gatewayPost('/order/init-order-page', ['ref_no' => $refNo, 'is_admin' => false]);

        $response->assertOk();
        $response->assertJsonPath('ref_no', $refNo);
        $response->assertJsonPath('order_id', $cartId);
        $response->assertJsonPath('is_paid', 1);
        $response->assertJsonPath('is_complete', 1);
        $response->assertJsonPath('detail.items.0.com_reg_no', '200701018579');
        $response->assertJsonPath('detail.items.0.children.0.gen_error', null);
        $this->assertStringEndsWith('/reports/infomina-berhad.pdf', $response->json('detail.items.0.children.0.download_url'));
        $response->assertJsonStructure(['paid_amount', 'is_paid', 'order_id', 'is_foc', 'is_use_point', 'ref_no', 'receipt_url', 'is_to_pay', 'is_complete', 'is_refund', 'is_cancel', 'created_at', 'name', 'cidp_org_id', 'is_einvoice_expired', 'placed_at', 'paid_at', 'detail', 'is_ispars_success']);

        $this->gatewayPost('/order/init-order-page', ['ref_no' => 'nope'])->assertStatus(404)->assertJsonPath('message', 'Order not found');
    }

    public function test_init_order_page_honours_report_delay(): void
    {
        config(['ssm_gateway_mock.report_delay_seconds' => 300]);
        $start = now()->startOfSecond();
        Carbon::setTestNow($start);
        $cartId = $this->addCompanyItem();
        $billId = $this->gatewayPost('/payment/ssm-product', ['cart_ids' => [$cartId]])->json('bill_id');
        $refNo = $this->gatewayPost('/order/init-thank-you-page', ['billplz_id' => $billId])->json('ref_no');

        $this->gatewayPost('/order/init-order-page', ['ref_no' => $refNo])
            ->assertOk()
            ->assertJsonPath('detail.items.0.children.0.download_url', null)
            ->assertJsonPath('detail.items.0.children.0.gen_error', null);

        Carbon::setTestNow($start->copy()->addSeconds(301));
        $this->assertNotNull($this->gatewayPost('/order/init-order-page', ['ref_no' => $refNo])->json('detail.items.0.children.0.download_url'));
    }

    public function test_orders_are_scoped_to_client(): void
    {
        $cartId = $this->addCompanyItem();
        $billId = $this->gatewayPost('/payment/ssm-product', ['cart_ids' => [$cartId]])->json('bill_id');
        $refNo = $this->gatewayPost('/order/init-thank-you-page', ['billplz_id' => $billId])->json('ref_no');

        [$other] = ApiClient::createWithSecret('other', null, 'ssm_gateway');
        Cache::put('ssm_gateway_token:other-token', $other->id, 3600);
        $headers = ['Authorization' => 'Bearer other-token'];

        $this->withHeaders($headers)->postJson('/ssm-gateway/order/init-checkout-page', ['cart_ids' => [$cartId]])->assertStatus(404);
        $this->withHeaders($headers)->postJson('/ssm-gateway/order/init-thank-you-page', ['billplz_id' => $billId])->assertStatus(404);
        $this->withHeaders($headers)->postJson('/ssm-gateway/order/init-order-page', ['ref_no' => $refNo])->assertStatus(404);
        $this->assertSame(1, SsmGatewayOrder::count());
    }
}
