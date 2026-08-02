<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class VerifyDnbCredentials
{
    public function handle(Request $request, Closure $next): Response
    {
        $xml = $request->getContent();
        $userId = $this->extractXmlValue($xml, 'USER_ID');
        $password = $this->extractXmlValue($xml, 'PASSWORD');

        if ($userId === null || $userId === '' || $password === null || $password === '') {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $apiClient = ApiClient::where('key', $userId)->first();

        if (
            $apiClient === null
            || ! Hash::check($password, $apiClient->secret_hash)
            || ! $apiClient->isActive()
            || $apiClient->purpose !== 'dnb'
        ) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }

    private function extractXmlValue(string $xml, string $tag): ?string
    {
        return preg_match("#<{$tag}>([^<]*)</{$tag}>#", $xml, $matches) ? trim($matches[1]) : null;
    }
}
