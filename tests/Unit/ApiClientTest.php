<?php

namespace Tests\Unit;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_with_secret_stores_hashed_secret_and_returns_plaintext_once(): void
    {
        [$apiClient, $secret] = ApiClient::createWithSecret('acme-app', 30);

        $this->assertNotEmpty($apiClient->key);
        $this->assertNotSame($secret, $apiClient->secret_hash);
        $this->assertTrue(Hash::check($secret, $apiClient->secret_hash));
    }

    public function test_no_expiry_when_expires_in_days_is_null(): void
    {
        [$apiClient] = ApiClient::createWithSecret('acme-app', null);

        $this->assertNull($apiClient->expires_at);
        $this->assertFalse($apiClient->isExpired());
    }

    public function test_is_expired_when_expiry_is_in_the_past(): void
    {
        $apiClient = ApiClient::create([
            'label' => 'acme-app',
            'key' => 'k',
            'secret_hash' => Hash::make('s'),
            'expires_at' => now()->subDay(),
        ]);

        $this->assertTrue($apiClient->isExpired());
        $this->assertFalse($apiClient->isActive());
    }

    public function test_is_revoked_when_revoked_at_is_set(): void
    {
        $apiClient = ApiClient::create([
            'label' => 'acme-app',
            'key' => 'k',
            'secret_hash' => Hash::make('s'),
            'revoked_at' => now(),
        ]);

        $this->assertTrue($apiClient->isRevoked());
        $this->assertFalse($apiClient->isActive());
    }

    public function test_is_active_when_not_revoked_and_not_expired(): void
    {
        [$apiClient] = ApiClient::createWithSecret('acme-app', 30);

        $this->assertTrue($apiClient->isActive());
    }
}
