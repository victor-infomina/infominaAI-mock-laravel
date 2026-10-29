<?php

namespace App\Services\WebhookInbox;

/**
 * Recomputes infominaAI-BE's callback/webhook signature:
 *   hex(HMAC-SHA256(secret, identifier . secret . referenceId))
 * identifier = partner key (partner mode) or client key (account mode).
 */
class WebhookHashVerifier
{
    public const VALID = 'valid';

    public const INVALID = 'invalid';

    public const UNCHECKED = 'unchecked';

    public static function compute(string $identifier, string $secret, string $referenceId): string
    {
        return hash_hmac('sha256', $identifier.$secret.$referenceId, $secret);
    }

    public static function status(?string $identifier, ?string $secret, ?string $referenceId, ?string $hash): string
    {
        foreach ([$identifier, $secret, $referenceId, $hash] as $value) {
            if ($value === null || $value === '') {
                return self::UNCHECKED;
            }
        }

        return hash_equals(self::compute($identifier, $secret, $referenceId), $hash)
            ? self::VALID
            : self::INVALID;
    }
}
