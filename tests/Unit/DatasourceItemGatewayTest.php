<?php

namespace Tests\Unit;

use App\Services\DatasourceItemGateway;
use PHPUnit\Framework\TestCase;

class DatasourceItemGatewayTest extends TestCase
{
    public function test_supported_items_returns_all_eight_known_items(): void
    {
        $items = DatasourceItemGateway::supportedItems();

        $this->assertCount(8, $items);
        $this->assertContains('company_profile_v2', $items);
        $this->assertContains('vietnam_company_profile', $items);
        $this->assertContains('singapore_company_profile', $items);
    }

    public function test_items_for_gateway_narrows_to_matching_items_only(): void
    {
        $asiaverifyItems = DatasourceItemGateway::itemsForGateway('asiaverify');

        $this->assertSame(
            ['vietnam_company_profile', 'thailand_company_profile', 'china_company_profile'],
            array_values($asiaverifyItems),
        );
    }

    public function test_gateway_for_maps_known_items(): void
    {
        $this->assertSame('ssm', DatasourceItemGateway::gatewayFor('company_profile_v2'));
        $this->assertSame('asiaverify', DatasourceItemGateway::gatewayFor('vietnam_company_profile'));
        $this->assertSame('dnb', DatasourceItemGateway::gatewayFor('singapore_company_profile'));
    }

    public function test_gateway_for_defaults_to_ssm_when_item_is_null(): void
    {
        $this->assertSame('ssm', DatasourceItemGateway::gatewayFor(null));
    }

    public function test_label_for_known_items(): void
    {
        $this->assertSame('AsiaVerify — Vietnam', DatasourceItemGateway::labelFor('vietnam_company_profile'));
        $this->assertSame('DNB — Singapore', DatasourceItemGateway::labelFor('singapore_company_profile'));
    }

    public function test_label_for_defaults_when_item_is_null(): void
    {
        $this->assertSame('SSM', DatasourceItemGateway::labelFor(null));
    }
}
