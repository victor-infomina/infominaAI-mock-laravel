<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiClientCredentials
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('x-Gateway-APIKey');
        $secret = $request->header('x-Gateway-APISecret');

        if (! is_string($key) || $key === '' || ! is_string($secret) || $secret === '') {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $apiClient = ApiClient::where('key', $key)->first();

        if ($apiClient === null || ! Hash::check($secret, $apiClient->secret_hash) || ! $apiClient->isActive()) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
