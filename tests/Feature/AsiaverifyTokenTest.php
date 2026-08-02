<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AsiaverifyTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_issues_token_for_valid_credentials(): void
    {
        [$apiClient, $secret] = ApiClient::createWithSecret('be-app', null, 'asiaverify');

        $response = $this->withHeaders([
            'Authorization' => $apiClient->key,
            'Sign' => $secret,
        ])->postJson('/token/create');

        $response->assertOk();
        $response->assertJsonPath('code', '200');
        $response->assertJsonPath('result.status', 'active');
        $response->assertJsonPath('result.tokenExpiry', '3600');
        $this->assertNotEmpty($response->json('result.token'));
    }

    public function test_rejects_missing_credentials(): void
    {
        $response = $this->postJson('/token/create');

        $response->assertOk();
        $response->assertJsonPath('code', '401');
        $response->assertJsonPath('errorCode', 'UNAUTHORIZED');
    }

    public function test_rejects_wrong_purpose_credentials(): void
    {
        [$apiClient, $secret] = ApiClient::createWithSecret('be-app', null, 'gateway');

        $response = $this->withHeaders([
            'Authorization' => $apiClient->key,
            'Sign' => $secret,
        ])->postJson('/token/create');

        $response->assertJsonPath('code', '401');
    }

    public function test_rejects_wrong_sign(): void
    {
        [$apiClient] = ApiClient::createWithSecret('be-app', null, 'asiaverify');

        $response = $this->withHeaders([
            'Authorization' => $apiClient->key,
            'Sign' => 'wrong-secret',
        ])->postJson('/token/create');

        $response->assertJsonPath('code', '401');
    }
}
