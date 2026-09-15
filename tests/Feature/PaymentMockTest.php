<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Senangpay-protocol payment mock. Mirrors infominaAI-mock's NestJS
 * mock-payment module so infominaAI-BE's `mock` payment gateway row can
 * point at this deployed app instead of a localhost-only server.
 */
class PaymentMockTest extends TestCase
{
    private const SECRET = 'test-senangpay-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.senangpay.secret_key' => self::SECRET]);
    }

    private function senangpayHash(string $statusId, string $orderId, string $transactionId, string $msg): string
    {
        return hash_hmac('sha256', self::SECRET.$statusId.$orderId.$transactionId.$msg, self::SECRET);
    }

    public function test_generate_hash_matches_senangpay_callback_formula(): void
    {
        $response = $this->postJson('/payment/generate-hash', [
            'statusId' => '1',
            'orderId' => 'order-123',
            'transactionId' => 'txn-456',
            'msg' => 'Payment_was_successful',
        ]);

        $response->assertOk();
        $response->assertJsonPath('hash', $this->senangpayHash('1', 'order-123', 'txn-456', 'Payment_was_successful'));
    }

    public function test_payment_page_renders_with_order_details_and_no_auth(): void
    {
        $response = $this->post('/payment/756173209342181', [
            'order_id' => 'order-123',
            'amount' => '12.50',
            'detail' => 'SSM company profile',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'return_url' => 'http://localhost:4300',
        ]);

        $response->assertOk();
        $response->assertSee('order-123');
        $response->assertSee('12.50');
        $response->assertSee('SSM company profile');
        $response->assertSee('action="'.url('/payment/756173209342181/complete').'"', false);
        $response->assertSee('name="return_url"', false);
        $response->assertSee('value="http://localhost:4300"', false);
        // Success is the default outcome, like the NestJS mock.
        $response->assertSee('<option value="1" selected', false);
    }

    public function test_payment_page_falls_back_to_configured_redirect_url(): void
    {
        config(['services.senangpay.redirect_url' => 'https://dev-aiexe.infomina.ai']);

        $response = $this->post('/payment/756173209342181', ['order_id' => 'order-123']);

        $response->assertOk();
        $response->assertSee('value="https://dev-aiexe.infomina.ai"', false);
    }

    public function test_payment_page_detects_frontend_origin_from_origin_header(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://dev-aiexe.infomina.ai'])
            ->post('/payment/756173209342181', ['order_id' => 'order-123']);

        $response->assertOk();
        $response->assertSee('value="https://dev-aiexe.infomina.ai"', false);
    }

    public function test_payment_page_falls_back_to_referer_origin_without_origin_header(): void
    {
        $response = $this->withHeaders(['Referer' => 'https://staging-aiexe.infomina.ai/search/purchase-summary?x=1'])
            ->post('/payment/756173209342181', ['order_id' => 'order-123']);

        $response->assertOk();
        $response->assertSee('value="https://staging-aiexe.infomina.ai"', false);
    }

    public function test_explicit_return_url_field_wins_over_detected_origin(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://dev-aiexe.infomina.ai'])
            ->post('/payment/756173209342181', [
                'order_id' => 'order-123',
                'return_url' => 'http://localhost:4300',
            ]);

        $response->assertOk();
        $response->assertSee('value="http://localhost:4300"', false);
    }

    public function test_null_origin_header_is_ignored_in_favour_of_configured_fallback(): void
    {
        config(['services.senangpay.redirect_url' => 'https://dev-aiexe.infomina.ai']);

        $response = $this->withHeaders(['Origin' => 'null'])
            ->post('/payment/756173209342181', ['order_id' => 'order-123']);

        $response->assertOk();
        $response->assertSee('value="https://dev-aiexe.infomina.ai"', false);
    }

    public function test_complete_redirects_to_return_url_with_signed_success_params(): void
    {
        $response = $this->post('/payment/756173209342181/complete', [
            'order_id' => 'order-123',
            'transaction_id' => 'txn-456',
            'status_id' => '1',
            'return_url' => 'http://localhost:4300',
        ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('http://localhost:4300/payment/result?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('1', $query['status_id']);
        $this->assertSame('order-123', $query['order_id']);
        $this->assertSame('txn-456', $query['transaction_id']);
        $this->assertSame('Payment_was_successful', $query['msg']);
        $this->assertSame($this->senangpayHash('1', 'order-123', 'txn-456', 'Payment_was_successful'), $query['hash']);
    }

    public function test_complete_with_failed_status_signs_failure_message(): void
    {
        $response = $this->post('/payment/756173209342181/complete', [
            'order_id' => 'order-123',
            'transaction_id' => 'txn-456',
            'status_id' => '0',
            'return_url' => 'http://localhost:4300/',
        ]);

        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('0', $query['status_id']);
        $this->assertSame('Payment_was_failed', $query['msg']);
        $this->assertSame($this->senangpayHash('0', 'order-123', 'txn-456', 'Payment_was_failed'), $query['hash']);
        $this->assertStringStartsWith('http://localhost:4300/payment/result?', $response->headers->get('Location'));
    }

    public function test_complete_rejects_unknown_status_id(): void
    {
        $response = $this->post('/payment/756173209342181/complete', [
            'order_id' => 'order-123',
            'transaction_id' => 'txn-456',
            'status_id' => '9',
            'return_url' => 'http://localhost:4300',
        ]);

        $response->assertStatus(422);
    }

    public function test_missing_secret_key_returns_service_unavailable(): void
    {
        config(['services.senangpay.secret_key' => null]);

        $response = $this->postJson('/payment/generate-hash', [
            'statusId' => '1',
            'orderId' => 'order-123',
            'transactionId' => 'txn-456',
            'msg' => 'Payment_was_successful',
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('error', fn (string $e) => str_contains($e, 'SENANGPAY_SECRET_KEY'));
    }
}
