<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifySsmGatewayToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $clientId = is_string($token) && $token !== '' ? Cache::get('ssm_gateway_token:'.$token) : null;
        $apiClient = $clientId !== null ? ApiClient::find($clientId) : null;

        if ($apiClient === null || ! $apiClient->isActive() || $apiClient->purpose !== 'ssm_gateway') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->attributes->set('ssm_gateway_client_id', $apiClient->id);
        $request->attributes->set('ssm_gateway_client', $apiClient);

        return $next($request);
    }
}
