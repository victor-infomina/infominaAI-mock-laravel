<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyGatewayCredentials
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = config('ssm_mock.api_key');
        $configuredSecret = config('ssm_mock.api_secret');

        if (
            ! is_string($configuredKey) || $configuredKey === ''
            || ! is_string($configuredSecret) || $configuredSecret === ''
            || $request->header('x-Gateway-APIKey') !== $configuredKey
            || $request->header('x-Gateway-APISecret') !== $configuredSecret
        ) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
