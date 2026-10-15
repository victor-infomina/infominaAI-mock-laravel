<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSsmGatewayToken;
use Tests\TestCase;

class SsmGatewayLoginTest extends TestCase
{
    use InteractsWithSsmGatewayToken;
    use RefreshDatabase;

    private ApiClient $client;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->client, $this->secret] = ApiClient::createWithSecret('be-dev', null, 'ssm_gateway');
    }

    private function login(array $overrides = [])
    {
        return $this->postJson('/ssm-gateway/user/login', array_merge([
            'email' => $this->client->key,
            'password' => $this->secret,
            'recaptchaToken' => 'any-token',
        ], $overrides));
    }

    public function test_login_returns_token_and_user_details(): void
    {
        $response = $this->login();

        $response->assertOk();
        $response->assertJsonStructure(['token', 'id', 'email', 'orgId', 'orgRole', 'roleId', 'privs', 'username', 'firstName', 'lastName']);
        $response->assertJsonPath('email', $this->client->key);
        $response->assertJsonPath('orgRole', 'owner');
        $response->assertJsonPath('isEmailVerified', 1);

        $token = $response->json('token');
        $this->assertSame($this->client->id, Cache::get('ssm_gateway_token:'.$token));
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->login(['password' => 'nope'])->assertStatus(401)->assertJsonPath('message', 'Invalid email or password');
    }

    public function test_login_rejects_unknown_email(): void
    {
        $this->login(['email' => 'ghost'])->assertStatus(401);
    }

    public function test_login_rejects_client_with_other_purpose(): void
    {
        [$gateway, $secret] = ApiClient::createWithSecret('plain', null, 'gateway');

        $this->login(['email' => $gateway->key, 'password' => $secret])->assertStatus(401);
    }

    public function test_login_rejects_revoked_client(): void
    {
        $this->client->update(['revoked_at' => now()]);

        $this->login()->assertStatus(401);
    }

    public function test_login_rejects_empty_captcha(): void
    {
        $this->login(['recaptchaToken' => ''])->assertStatus(422)->assertJsonPath('message', 'Captcha verification failed');
        $this->postJson('/ssm-gateway/user/login', ['email' => $this->client->key, 'password' => $this->secret])
            ->assertStatus(422);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/ssm-gateway/user/login', ['recaptchaToken' => 'x'])->assertStatus(422);
    }

    public function test_get_balance_with_valid_token(): void
    {
        $this->setUpSsmGatewayToken();

        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/point/get-balance', ['_user_id' => 1])
            ->assertOk()
            ->assertJsonPath('balance', 999)
            ->assertJsonPath('is_show_point', true);
    }

    public function test_get_balance_without_token_is_401(): void
    {
        $this->postJson('/ssm-gateway/point/get-balance', [])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_get_balance_with_unknown_token_is_401(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer nope'])
            ->postJson('/ssm-gateway/point/get-balance', [])
            ->assertStatus(401);
    }

    public function test_token_of_revoked_client_is_rejected(): void
    {
        $this->setUpSsmGatewayToken();
        $this->ssmGatewayClient->update(['revoked_at' => now()]);

        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/point/get-balance', [])
            ->assertStatus(401);
    }

    public function test_token_ttl_comes_from_config(): void
    {
        config(['ssm_gateway_mock.token_ttl_seconds' => 1]);
        $token = $this->login()->json('token');

        $this->travel(2)->seconds();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/ssm-gateway/point/get-balance', [])
            ->assertStatus(401);
    }
}
