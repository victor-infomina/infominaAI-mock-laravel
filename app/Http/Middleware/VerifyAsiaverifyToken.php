<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyAsiaverifyToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('token');

        if (! is_string($token) || $token === '' || ! Cache::get("asiaverify_token:{$token}")) {
            return response()->json([
                'code' => '401',
                'message' => 'Missing or invalid token',
                'result' => null,
                'lastUpdated' => now()->toDateTimeString(),
                'errorCode' => 'UNAUTHORIZED',
            ]);
        }

        return $next($request);
    }
}
