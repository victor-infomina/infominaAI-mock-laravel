<?php

namespace App\Services\SsmGateway;

class ProductCatalogue
{
    public const PRICE = 15;

    public const SERVICE_FEE = 2;

    /** @var array<int, array{product_id:int, product_name:string, variant_id:int, lang:string, is_ctc:int}> */
    private array $byVariantId = [];

    /** @var list<array> */
    private array $products = [];

    public function __construct()
    {
        $productId = 0;

        foreach (VariantTable::STAGING as $key => $byCtc) {
            $productId++;
            $label = self::label($key);
            $variants = [];

            foreach (['CTC' => 1, 'NON_CTC' => 0] as $ctcKey => $isCtc) {
                foreach ($byCtc[$ctcKey] ?? [] as $langKey => $variantId) {
                    $lang = strtolower($langKey);
                    $variants[] = [
                        'id' => $variantId,
                        'lang' => $lang,
                        'cart_id' => null,
                        'is_ctc' => $isCtc,
                        'amount' => self::PRICE,
                        'service_fee' => self::SERVICE_FEE,
                        'cart_year1' => null,
                        'cart_year2' => null,
                    ];
                    $this->byVariantId[$variantId] = [
                        'product_id' => $productId,
                        'product_name' => $label,
                        'variant_id' => $variantId,
                        'lang' => $lang,
                        'is_ctc' => $isCtc,
                    ];
                }
            }

            $this->products[] = [
                'id' => $productId,
                'is_biz_cert' => $key === 'BUSINESS_CERTIFICATE',
                'img' => '',
                'label' => $label,
                'sample_url' => '[]',
                'variants' => $variants,
                'is_select_year' => 0,
            ];
        }
    }

    public function findVariant(int $variantId): ?array
    {
        return $this->byVariantId[$variantId] ?? null;
    }

    public function listResponse(): array
    {
        return [
            'is_validating' => false,
            'product_with_validation' => [],
            'valid_product_ids' => array_map(fn (array $p) => $p['id'], $this->products),
            'products' => ['1' => $this->products],
            'categories' => ['1' => ['label' => 'SSM Products', 'description' => '']],
            'isForeign' => false,
            'branchInfo' => null,
            'yearInfoFinancialYear' => null,
            'fyeInfoFinancialYear' => null,
            'isRobExpired' => false,
            'ctcDisabled' => false,
        ];
    }

    private static function label(string $key): string
    {
        $label = ucwords(strtolower(str_replace('_', ' ', $key)));

        return str_replace(
            ['Acgs', 'Brecle', 'Llp', 'Of ', 'And '],
            ['(ACGS)', '(BRECLE)', 'LLP', 'of ', 'and '],
            $label
        );
    }
}
