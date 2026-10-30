<?php

namespace App\Services\WebhookInbox;

/**
 * Recomputes infominaAI-BE's payload signatures (AE-479):
 *
 *   webhook  - header `X-Signature: t=<unix>,v1=<hex>` with
 *              v1 = hex(HMAC-SHA256(secret, t . "." . rawBody))
 *   callback - query params `ts` + `signature` with
 *              signature = hex(HMAC-SHA256(secret, canonical)), canonical =
 *              every param except `signature`, sorted by name, "k=v" joined by "&"
 *
 * A signature that matches but is older than MAX_AGE_SECONDS is reported as
 * stale, the same check the API doc asks receivers to make.
 */
class WebhookSignatureVerifier
{
    public const VALID = 'valid';

    public const INVALID = 'invalid';

    public const STALE = 'stale';

    public const UNCHECKED = 'unchecked';

    public const MAX_AGE_SECONDS = 300;

    public static function signWebhook(string $secret, string $rawBody, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    public static function webhookStatus(?string $secret, ?string $header, string $rawBody, ?int $now = null): string
    {
        if ($secret === null || $secret === '' || $header === null || $header === '') {
            return self::UNCHECKED;
        }
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $parts[trim((string) $key)] = $value;
        }
        $t = $parts['t'] ?? null;
        $v1 = $parts['v1'] ?? null;
        if ($t === null || ! ctype_digit($t) || $v1 === null) {
            return self::INVALID;
        }

        if (! hash_equals(hash_hmac('sha256', $t.'.'.$rawBody, $secret), $v1)) {
            return self::INVALID;
        }

        return abs(($now ?? time()) - (int) $t) > self::MAX_AGE_SECONDS ? self::STALE : self::VALID;
    }

    /** @param array<string, mixed> $params */
    public static function canonicalCallback(array $params): string
    {
        unset($params['signature']);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key.'='.(is_scalar($value) ? (string) $value : json_encode($value));
        }

        return implode('&', $pairs);
    }

    /** @param array<string, mixed> $params */
    public static function callbackStatus(?string $secret, array $params, ?int $now = null): string
    {
        $signature = $params['signature'] ?? null;
        if ($secret === null || $secret === '' || ! is_string($signature) || $signature === '') {
            return self::UNCHECKED;
        }
        $ts = $params['ts'] ?? null;
        if (! is_string($ts) || ! ctype_digit($ts)) {
            return self::INVALID;
        }

        if (! hash_equals(hash_hmac('sha256', self::canonicalCallback($params), $secret), $signature)) {
            return self::INVALID;
        }

        return abs(($now ?? time()) - (int) $ts) > self::MAX_AGE_SECONDS ? self::STALE : self::VALID;
    }
}
