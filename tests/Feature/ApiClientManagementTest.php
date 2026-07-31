<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/tokens');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_admin_can_view_token_list(): void
    {
        $admin = User::factory()->create();
        ApiClient::createWithSecret('existing-client', null);

        $response = $this->actingAs($admin)->get('/tokens');

        $response->assertOk();
        $response->assertSee('existing-client');
    }

    public function test_generating_a_token_shows_the_plaintext_secret_once(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->post('/tokens', [
            'label' => 'new-client',
            'expires_in_days' => '30',
        ]);

        $response->assertRedirect(route('tokens.index'));

        $apiClient = ApiClient::where('label', 'new-client')->firstOrFail();
        $this->assertNotNull($apiClient->expires_at);
        $response->assertSessionHas('generatedKey', $apiClient->key);
        $response->assertSessionHas('generatedSecret');
    }

    public function test_generating_a_token_with_no_expiry(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post('/tokens', [
            'label' => 'no-expiry-client',
            'expires_in_days' => '',
        ])->assertRedirect(route('tokens.index'));

        $apiClient = ApiClient::where('label', 'no-expiry-client')->firstOrFail();
        $this->assertNull($apiClient->expires_at);
    }

    public function test_revoking_a_token_marks_it_revoked_and_blocks_gateway_access(): void
    {
        $admin = User::factory()->create();
        [$apiClient, $secret] = ApiClient::createWithSecret('to-revoke', null);

        $this->actingAs($admin)->post("/tokens/{$apiClient->id}/revoke")
            ->assertRedirect(route('tokens.index'));

        $this->assertNotNull($apiClient->fresh()->revoked_at);

        $gatewayResponse = $this->withHeaders([
            'x-Gateway-APIKey' => $apiClient->key,
            'x-Gateway-APISecret' => $secret,
        ])->postJson('/get-search-entity', ['regNo' => 'whatever', 'entityType' => 'company']);

        $gatewayResponse->assertStatus(401);
    }
}
