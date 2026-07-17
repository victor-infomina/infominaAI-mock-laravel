<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyGatewayCredentials
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('x-Gateway-APIKey');
        $secret = $request->header('x-Gateway-APISecret');

        if ($key !== config('ssm_mock.api_key') || $secret !== config('ssm_mock.api_secret')) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
