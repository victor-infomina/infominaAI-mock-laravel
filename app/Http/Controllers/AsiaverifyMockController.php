<?php

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Services\AsiaverifyCaseRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AsiaverifyMockController extends Controller
{
    public function __construct(private readonly AsiaverifyCaseRepository $cases)
    {
    }

    public function createToken(Request $request): JsonResponse
    {
        $key = $request->header('Authorization');
        $sign = $request->header('Sign');

        $apiClient = is_string($key) && $key !== '' ? ApiClient::where('key', $key)->first() : null;

        $unauthorized = $apiClient === null
            || ! is_string($sign) || $sign === ''
            || ! Hash::check($sign, $apiClient->secret_hash)
            || ! $apiClient->isActive()
            || $apiClient->purpose !== 'asiaverify';

        if ($unauthorized) {
            return response()->json([
                'code' => '401',
                'message' => 'Missing or invalid Authorization/Sign',
                'result' => null,
                'lastUpdated' => now()->toDateTimeString(),
                'errorCode' => 'UNAUTHORIZED',
            ]);
        }

        $token = Str::random(40);
        $ttl = (int) config('asiaverify_mock.token_ttl_seconds');
        Cache::put("asiaverify_token:{$token}", true, $ttl);

        return response()->json([
            'code' => '200',
            'message' => 'Request succeeded',
            'result' => [
                'token' => $token,
                'tokenExpiry' => (string) $ttl,
                'status' => 'active',
            ],
            'lastUpdated' => now()->toDateTimeString(),
        ]);
    }
}
