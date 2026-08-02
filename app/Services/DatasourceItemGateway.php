<?php

namespace App\Services;

class DatasourceItemGateway
{
    private const MAP = [
        'company_profile_v2' => ['gateway' => 'ssm', 'label' => 'SSM — Company'],
        'business_profile_v2' => ['gateway' => 'ssm', 'label' => 'SSM — Business'],
        'llp_profile' => ['gateway' => 'ssm', 'label' => 'SSM — LLP'],
        'vietnam_company_profile' => ['gateway' => 'asiaverify', 'label' => 'AsiaVerify — Vietnam'],
        'thailand_company_profile' => ['gateway' => 'asiaverify', 'label' => 'AsiaVerify — Thailand'],
        'china_company_profile' => ['gateway' => 'asiaverify', 'label' => 'AsiaVerify — China'],
        'singapore_company_profile' => ['gateway' => 'dnb', 'label' => 'DNB — Singapore'],
        'indonesia_company_profile' => ['gateway' => 'dnb', 'label' => 'DNB — Indonesia'],
    ];

    public static function supportedItems(): array
    {
        return array_keys(self::MAP);
    }

    public static function itemsForGateway(string $gateway): array
    {
        return array_keys(array_filter(self::MAP, fn (array $entry) => $entry['gateway'] === $gateway));
    }

    public static function gatewayFor(?string $item): string
    {
        return self::MAP[$item]['gateway'] ?? 'ssm';
    }

    public static function labelFor(?string $item): string
    {
        return self::MAP[$item]['label'] ?? 'SSM';
    }
}
