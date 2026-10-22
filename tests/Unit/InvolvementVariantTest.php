<?php

namespace Tests\Unit;

use App\Services\SsmGateway\InvolvementVariant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvolvementVariantTest extends TestCase
{
    public static function variants(): array
    {
        return [
            [1060, 1048, 'director-ctc-en'], [1062, 1050, 'director-ctc-my'],
            [1059, 1047, 'director-plain-en'], [1061, 1049, 'director-plain-my'],
            [1057, 1045, 'shareholder-ctc-en'], [1055, 1043, 'shareholder-ctc-my'],
            [1058, 1046, 'shareholder-plain-en'], [1056, 1044, 'shareholder-plain-my'],
            [1053, 1041, 'secretary-ctc-en'], [1051, 1039, 'secretary-ctc-my'],
            [1054, 1042, 'secretary-plain-en'], [1052, 1040, 'secretary-plain-my'],
        ];
    }

    #[DataProvider('variants')]
    public function test_resolves_both_environments(int $prod, int $staging, string $identity): void
    {
        $this->assertSame($identity, InvolvementVariant::fromProduction($prod));
        $this->assertSame($identity, InvolvementVariant::fromStaging($staging));
        $this->assertTrue(InvolvementVariant::isValid($identity));
    }

    public function test_colliding_ids_do_not_cross_environments(): void
    {
        $this->assertNull(InvolvementVariant::fromProduction(1045));
        $this->assertSame('shareholder-ctc-en', InvolvementVariant::fromStaging(1045));
        $this->assertNull(InvolvementVariant::fromStaging(158));
        $this->assertFalse(InvolvementVariant::isValid('../report'));
    }

    public function test_other_involvement_products_are_unsupported(): void
    {
        $this->assertNull(InvolvementVariant::fromStaging(1025));
        $this->assertNull(InvolvementVariant::fromStaging(1033));
        $this->assertNull(InvolvementVariant::fromStaging(1037));
    }

    public function test_lists_twelve_distinct_identities(): void
    {
        $this->assertCount(12, array_unique(InvolvementVariant::identities()));
        $this->assertSame(array_values(InvolvementVariant::identities()), InvolvementVariant::identities());
    }
}
