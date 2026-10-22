<?php

namespace App\Services\SsmGateway;

/**
 * Maps involvement variant ids to the `{product}-{ctc|plain}-{en|my}` identity that names a sample PDF.
 * Production and staging reuse the same numbers for different products, so each environment has its own lookup:
 * imports read production ids (infominaAI-BE ssm-variant-ids.prod.const.ts), served orders carry staging ids.
 */
final class InvolvementVariant
{
    private const PRODUCTION = [
        1060 => 'director-ctc-en', 1062 => 'director-ctc-my',
        1059 => 'director-plain-en', 1061 => 'director-plain-my',
        1057 => 'shareholder-ctc-en', 1055 => 'shareholder-ctc-my',
        1058 => 'shareholder-plain-en', 1056 => 'shareholder-plain-my',
        1053 => 'secretary-ctc-en', 1051 => 'secretary-ctc-my',
        1054 => 'secretary-plain-en', 1052 => 'secretary-plain-my',
    ];

    private const STAGING_PRODUCTS = [
        'DIRECTOR_INVOLVEMENT' => 'director',
        'SHAREHOLDER_INVOLVEMENT' => 'shareholder',
        'SECRETARY_INVOLVEMENT' => 'secretary',
    ];

    public static function fromProduction(int $variantId): ?string
    {
        return self::PRODUCTION[$variantId] ?? null;
    }

    public static function fromStaging(int $variantId): ?string
    {
        foreach (self::STAGING_PRODUCTS as $product => $name) {
            foreach (VariantTable::STAGING[$product] as $ctc => $languages) {
                foreach ($languages as $lang => $id) {
                    if ($id === $variantId) {
                        return $name.'-'.($ctc === 'CTC' ? 'ctc' : 'plain').'-'.strtolower($lang);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function identities(): array
    {
        return array_values(self::PRODUCTION);
    }

    public static function isValid(string $identity): bool
    {
        return in_array($identity, self::identities(), true);
    }
}
