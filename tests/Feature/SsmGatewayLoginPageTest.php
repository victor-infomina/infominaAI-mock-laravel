<?php

namespace Tests\Feature;

use Tests\TestCase;

class SsmGatewayLoginPageTest extends TestCase
{
    public function test_login_page_renders_recaptcha_v2_widget(): void
    {
        $response = $this->get('/ssm-gateway/login');

        $response->assertOk();
        $this->assertSame('text/html; charset=utf-8', strtolower($response->headers->get('Content-Type')));
        $response->assertSee('class="g-recaptcha"', false);
        $response->assertSee('data-sitekey="6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI"', false);
        $response->assertSee('https://www.google.com/recaptcha/api.js', false);
    }

    public function test_sitekey_is_configurable(): void
    {
        config(['ssm_gateway_mock.recaptcha_sitekey' => 'custom-key']);

        $this->get('/ssm-gateway/login')->assertSee('data-sitekey="custom-key"', false);
    }

    public function test_login_page_ignores_basic_auth_header(): void
    {
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('infomina:whatever')])
            ->get('/ssm-gateway/login')
            ->assertOk();
    }
}
