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
            'purpose' => 'gateway',
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
            'purpose' => 'gateway',
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

    public function test_generating_a_token_with_admin_sync_purpose(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post('/tokens', [
            'label' => 'local-sync-tool',
            'purpose' => 'admin_sync',
            'expires_in_days' => '',
        ])->assertRedirect(route('tokens.index'));

        $apiClient = ApiClient::where('label', 'local-sync-tool')->firstOrFail();
        $this->assertSame('admin_sync', $apiClient->purpose);
    }

    public function test_token_list_shows_purpose(): void
    {
        $admin = User::factory()->create();
        ApiClient::createWithSecret('local-sync-tool', null, 'admin_sync');

        $response = $this->actingAs($admin)->get('/tokens');

        $response->assertSee('admin_sync');
    }

    public function test_generating_a_token_with_asiaverify_purpose(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post('/tokens', [
            'label' => 'be-app-asiaverify',
            'purpose' => 'asiaverify',
            'expires_in_days' => '',
        ])->assertRedirect(route('tokens.index'));

        $apiClient = ApiClient::where('label', 'be-app-asiaverify')->firstOrFail();
        $this->assertSame('asiaverify', $apiClient->purpose);
    }

    public function test_generating_a_token_with_dnb_purpose(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post('/tokens', [
            'label' => 'be-app-dnb',
            'purpose' => 'dnb',
            'expires_in_days' => '',
        ])->assertRedirect(route('tokens.index'));

        $apiClient = ApiClient::where('label', 'be-app-dnb')->firstOrFail();
        $this->assertSame('dnb', $apiClient->purpose);
    }
}
