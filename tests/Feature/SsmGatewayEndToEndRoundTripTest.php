<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SsmGatewayEndToEndRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $cases = sys_get_temp_dir().'/ssm-gateway-e2e-cases-'.uniqid();
        mkdir($cases.'/infomina-berhad', 0777, true);
        file_put_contents($cases.'/infomina-berhad/meta.json', json_encode([
            'regNo' => '200701018579', 'companyName' => 'INFOMINA BERHAD', 'entityType' => 'company',
        ]));
        file_put_contents($cases.'/infomina-berhad/report.pdf', '%PDF-1.4 company');
        config(['ssm_mock.cases_path' => $cases]);

        $inv = sys_get_temp_dir().'/ssm-gateway-e2e-inv-'.uniqid();
        mkdir($inv.'/900101015555', 0777, true);
        file_put_contents($inv.'/900101015555/meta.json', json_encode([
            'idType' => 'MK', 'idValue' => '900101015555', 'name' => 'AHMAD BIN ALI',
        ]));
        file_put_contents($inv.'/900101015555/report.pdf', '%PDF-1.4 involvement');
        config(['ssm_gateway_mock.involvements_path' => $inv]);

        // Step 0: the BE's login/complete → POST /user/login
        [$client, $secret] = ApiClient::createWithSecret('be-dev', null, 'ssm_gateway');
        $this->token = $this->postJson('/ssm-gateway/user/login', [
            'email' => $client->key, 'password' => $secret, 'recaptchaToken' => 'solved',
        ])->assertOk()->json('token');
    }

    private function api(string $path, array $body)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->postJson('/ssm-gateway'.$path, array_merge(
            ['_user_id' => 1, '_org_id' => 10, '_org_role' => 'owner', '_role_id' => 2, '_testing_secret' => null],
            $body
        ));
    }

    private function checkoutPayAndPoll(int $cartId, string $expectedRegNo, string $expectedPdfBody): void
    {
        // 5. init-checkout-page
        $address = $this->api('/order/init-checkout-page', ['cart_ids' => [$cartId]])->assertOk()->json('address');

        // 6. payment/ssm-product
        $billId = $this->api('/payment/ssm-product', [
            'cart_ids' => [$cartId], 'is_use_point' => false, 'is_use_pay_later' => true, 'is_foc' => false,
            'full_name' => $address['full_name'], 'phone' => $address['phone'], 'email' => $address['email'],
            'address1' => $address['address1'], 'address2' => $address['address2'], 'city' => $address['city'],
            'state_code' => $address['state_code'], 'zipcode' => $address['zipcode'], 'country_code' => $address['country_code'],
        ])->assertOk()->json('bill_id');

        // 7. init-thank-you-page
        $refNo = $this->api('/order/init-thank-you-page', ['billplz_id' => $billId])->assertOk()->json('ref_no');
        $this->assertNotEmpty($refNo);

        // Fallback poll (cleanupStuckAwaitingPdf → checkOrderStatus)
        $order = $this->api('/order/init-order-page', ['ref_no' => $refNo, 'is_admin' => false])->assertOk();
        $item = $order->json('detail.items.0');
        $this->assertSame($expectedRegNo, $item['com_reg_no']);
        $this->assertNull($item['children'][0]['gen_error']);
        $downloadUrl = $item['children'][0]['download_url'];
        $this->assertNotNull($downloadUrl);

        // axios.get(download_url) with no headers
        $pdf = $this->get(parse_url($downloadUrl, PHP_URL_PATH));
        $pdf->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($expectedPdfBody, file_get_contents($pdf->baseResponse->getFile()->getPathname()));
    }

    public function test_company_purchase_flow(): void
    {
        // 1. company/search
        $search = $this->api('/company/search', ['search' => '200701018579', 'type_id' => 0, 'page' => 1, 'offset' => 10, 'sort' => []])->assertOk();
        $slug = $search->json('data.0.slug');

        // 2. company/get-info
        $info = $this->api('/company/get-info', ['slug' => $slug])->assertOk();
        $companyId = $info->json('id');

        // 3. product/list-by-company (result ignored by the BE)
        $this->api('/product/list-by-company', ['slug' => $slug])->assertOk();

        // 4. cart/add-item with String(id)
        $add = $this->api('/cart/add-item', ['variant_id' => 160, 'company_id' => (string) $companyId])->assertOk();
        $this->assertTrue($add->json('isOk'));

        $this->checkoutPayAndPoll($add->json('cart_id'), '200701018579', '%PDF-1.4 company');
    }

    public function test_personal_involvement_purchase_flow(): void
    {
        // 1. personal-involvement/submission
        $ref = $this->api('/personal-involvement/submission', ['idType' => 'MK', 'idValue' => '900101015555'])->assertOk()->json('data.selectionRefNo');

        // 2. cart/add-item with the selection ref
        $add = $this->api('/cart/add-item', ['variant_id' => 1047, 'company_id' => $ref, 'personal_involvement' => true])->assertOk();

        $this->checkoutPayAndPoll($add->json('cart_id'), '900101015555', '%PDF-1.4 involvement');
    }

    public function test_token_status_probe_passes_after_login(): void
    {
        $this->api('/point/get-balance', [])->assertOk();
    }
}
