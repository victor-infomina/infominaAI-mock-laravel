<?php

namespace Tests\Unit;

use App\Services\SsmGateway\ProductCatalogue;
use App\Services\SsmGateway\VariantTable;
use PHPUnit\Framework\TestCase;

class SsmGatewayProductCatalogueTest extends TestCase
{
    private ProductCatalogue $catalogue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue = new ProductCatalogue;
    }

    public function test_every_staging_variant_id_appears_exactly_once(): void
    {
        $expected = [];
        foreach (VariantTable::STAGING as $byCtc) {
            foreach ($byCtc as $byLang) {
                foreach ($byLang as $id) {
                    $expected[] = $id;
                }
            }
        }

        $seen = [];
        foreach ($this->catalogue->listResponse()['products'] as $products) {
            foreach ($products as $product) {
                foreach ($product['variants'] as $variant) {
                    $seen[] = $variant['id'];
                }
            }
        }

        sort($expected);
        sort($seen);
        $this->assertSame($expected, $seen);
        $this->assertSame(count($expected), count(array_unique($expected)), 'staging table has duplicate ids');
    }

    public function test_find_variant_known_ids_used_by_be(): void
    {
        $companyCtcEn = $this->catalogue->findVariant(160);
        $this->assertSame(1, $companyCtcEn['is_ctc']);
        $this->assertSame('en', $companyCtcEn['lang']);
        $this->assertSame('Company Profile', $companyCtcEn['product_name']);

        $directorNonCtcEn = $this->catalogue->findVariant(1047);
        $this->assertSame(0, $directorNonCtcEn['is_ctc']);
        $this->assertSame('Director Involvement', $directorNonCtcEn['product_name']);

        $bizCertMy = $this->catalogue->findVariant(203);
        $this->assertSame('my', $bizCertMy['lang']);
        $this->assertSame(1, $bizCertMy['is_ctc']);

        $this->assertNull($this->catalogue->findVariant(999999));
    }

    public function test_list_response_envelope(): void
    {
        $body = $this->catalogue->listResponse();

        $this->assertFalse($body['is_validating']);
        $this->assertSame([], $body['product_with_validation']);
        $this->assertIsArray($body['products']);
        $this->assertArrayHasKey('1', $body['products']);
        $this->assertSame(count($body['products']['1']), count($body['valid_product_ids']));
        $this->assertSame('SSM Products', $body['categories']['1']['label']);
        $this->assertFalse($body['isForeign']);
        $this->assertNull($body['branchInfo']);
        $this->assertFalse($body['ctcDisabled']);

        $first = $body['products']['1'][0];
        $this->assertSame(['id', 'is_biz_cert', 'img', 'label', 'sample_url', 'variants', 'is_select_year'], array_keys($first));
        $this->assertSame(['id', 'lang', 'cart_id', 'is_ctc', 'amount', 'service_fee', 'cart_year1', 'cart_year2'], array_keys($first['variants'][0]));
    }

    public function test_product_ids_are_sequential_and_stable(): void
    {
        $ids = array_map(fn ($p) => $p['id'], $this->catalogue->listResponse()['products']['1']);
        $this->assertSame(range(1, count($ids)), $ids);
        $this->assertSame(1, $this->catalogue->findVariant(2)['product_id']); // ACGS is the first table key
    }
}
