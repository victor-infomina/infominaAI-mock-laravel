<?php

namespace App\Http\Controllers\SsmGateway;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function loginPage(): View
    {
        return view('ssm-gateway.login', [
            'sitekey' => config('ssm_gateway_mock.recaptcha_sitekey'),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $captcha = $request->input('recaptchaToken');

        if (! is_string($captcha) || $captcha === '') {
            return response()->json(['message' => 'Captcha verification failed'], 422);
        }

        $apiClient = ApiClient::where('key', $validated['email'])->first();

        if (
            $apiClient === null
            || ! Hash::check($validated['password'], $apiClient->secret_hash)
            || ! $apiClient->isActive()
            || $apiClient->purpose !== 'ssm_gateway'
        ) {
            return response()->json(['message' => 'Invalid email or password'], 401);
        }

        $token = Str::random(60);
        Cache::put('ssm_gateway_token:'.$token, $apiClient->id, (int) config('ssm_gateway_mock.token_ttl_seconds'));

        return response()->json([
            'token' => $token,
            'id' => 1,
            'email' => $apiClient->key,
            'phone' => '0123456789',
            'dob' => '1990-01-01',
            'isEmailVerified' => 1,
            'firstName' => 'SSM',
            'lastName' => 'Mock',
            'username' => $apiClient->key,
            'roleId' => 2,
            'privs' => [],
            'hasApprovalListing' => null,
            'hasAdminAccess' => false,
            'hasCidAccess' => false,
            'orgId' => 10,
            'orgNo' => 'ORG-MOCK',
            'orgName' => 'SSM Mock Org',
            'orgPlan' => 'Pay Later',
            'orgRole' => 'owner',
            'userOrgName' => 'SSM Mock Org',
            'userOrgNo' => 'ORG-MOCK',
            'corporateName' => null,
            'isTaxProfileComplete' => true,
        ]);
    }

    public function balance(): JsonResponse
    {
        return response()->json(['balance' => 999, 'is_show_point' => true]);
    }
}
