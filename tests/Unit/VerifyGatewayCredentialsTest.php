<?php

namespace Tests\Unit;

use App\Http\Middleware\VerifyGatewayCredentials;
use Illuminate\Http\Request;
use Tests\TestCase;

class VerifyGatewayCredentialsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ssm_mock.api_key' => 'test-key', 'ssm_mock.api_secret' => 'test-secret']);
    }

    private function nextReturningOk(): \Closure
    {
        return fn (Request $request) => response()->json(['ok' => true]);
    }

    public function test_allows_request_with_correct_credentials(): void
    {
        $request = Request::create('/whatever', 'POST');
        $request->headers->set('x-Gateway-APIKey', 'test-key');
        $request->headers->set('x-Gateway-APISecret', 'test-secret');

        $response = (new VerifyGatewayCredentials())->handle($request, $this->nextReturningOk());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_rejects_request_with_missing_credentials(): void
    {
        $request = Request::create('/whatever', 'POST');

        $response = (new VerifyGatewayCredentials())->handle($request, $this->nextReturningOk());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rejects_request_with_wrong_secret(): void
    {
        $request = Request::create('/whatever', 'POST');
        $request->headers->set('x-Gateway-APIKey', 'test-key');
        $request->headers->set('x-Gateway-APISecret', 'wrong-secret');

        $response = (new VerifyGatewayCredentials())->handle($request, $this->nextReturningOk());

        $this->assertSame(401, $response->getStatusCode());
    }
}
