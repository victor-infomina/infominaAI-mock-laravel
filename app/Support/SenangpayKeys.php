<?php

namespace App\Support;

/**
 * Parses SENANGPAY_SECRET_KEYS: a list of `frontend-host=secret` pairs
 * separated by `;`, `,` or newlines, e.g.
 *
 *   localhost=7313-…;dev-aiexe.infomina.ai=SK-V…;staging-aiexe.infomina.ai=SK-N…
 *
 * Hosts are lower-cased; keys are taken verbatim (they may contain `=`).
 * The resulting map is both the per-environment key table and the allowlist
 * of frontends that may use the payment mock.
 *
 * @return array<string, string>
 */
final class SenangpayKeys
{
    public static function parse(?string $raw): array
    {
        $keys = [];
        foreach (preg_split('/[;,\r\n]+/', (string) $raw) ?: [] as $pair) {
            $pair = trim($pair);
            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }
            [$host, $secret] = explode('=', $pair, 2);
            $host = strtolower(trim($host));
            $secret = trim($secret);
            if ($host !== '' && $secret !== '') {
                $keys[$host] = $secret;
            }
        }

        return $keys;
    }
}
