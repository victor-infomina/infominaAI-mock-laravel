<?php

namespace Tests\Unit;

use App\Http\Middleware\VerifyApiClientCredentials;
use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class VerifyApiClientCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private function nextReturningOk(): \Closure
    {
        return fn (Request $request) => response()->json(['ok' => true]);
    }

    public function test_allows_request_with_correct_credentials(): void
    {
        [$apiClient, $secret] = ApiClient::createWithSecret('acme-app', null);

        $request = Request::create('/whatever', 'POST');
        $request->headers->set('x-Gateway-APIKey', $apiClient->key);
        $request->headers->set('x-Gateway-APISecret', $secret);

        $response = (new VerifyApiClientCredentials())->handle($request, $this->nextReturningOk());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_rejects_request_with_missing_credentials(): void
    {
        $request = Request::create('/whatever', 'POST');

        $response = (new VerifyApiClientCredentials())->handle($request, $this->nextReturningOk());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rejects_request_with_wrong_secret(): void
    {
        [$apiClient] = ApiClient::createWithSecret('acme-app', null);

        $request = Request::create('/whatever', 'POST');
        $request->headers->set('x-Gateway-APIKey', $apiClient->key);
        $request->headers->set('x-Gateway-APISecret', 'wrong-secret');

        $response = (new VerifyApiClientCredentials())->handle($request, $this->nextReturningOk());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rejects_request_with_unknown_key(): void
    {
        $request = Request::create('/whatever', 'POST');
        $request->headers->set('x-Gateway-APIKey', 'unknown-key');
        $request->headers->set('x-Gateway-APISecret', 'whatever');

        $response = (new VerifyApiClientCredentials())->handle($request, $this->nextReturningOk());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rejects_revoked_client(): void
    {
        [$apiClient, $secret] = ApiClient::createWithSecret('acme-app', null);
        $apiClient->update(['revoked_at' => now()]);

        $request = Request::create('/whatever', 'POST');
        $request->headers->set('x-Gateway-APIKey', $apiClient->key);
        $request->headers->set('x-Gateway-APISecret', $secret);

        $response = (new VerifyApiClientCredentials())->handle($request, $this->nextReturningOk());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rejects_expired_client(): void
    {
        [$apiClient, $secret] = ApiClient::createWithSecret('acme-app', null);
        $apiClient->update(['expires_at' => now()->subDay()]);

        $request = Request::create('/whatever', 'POST');
        $request->headers->set('x-Gateway-APIKey', $apiClient->key);
        $request->headers->set('x-Gateway-APISecret', $secret);

        $response = (new VerifyApiClientCredentials())->handle($request, $this->nextReturningOk());

        $this->assertSame(401, $response->getStatusCode());
    }
}
