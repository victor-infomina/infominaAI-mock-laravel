<?php

namespace Tests\Unit;

use App\Support\SenangpayKeys;
use PHPUnit\Framework\TestCase;

class SenangpayKeysTest extends TestCase
{
    public function test_parses_host_equals_key_pairs_separated_by_semicolon_comma_or_newline(): void
    {
        $parsed = SenangpayKeys::parse("localhost=7313-1; dev-aiexe.infomina.ai=SK-V1,\n Staging-AIEXE.infomina.ai = SK-N1 ;");

        $this->assertSame([
            'localhost' => '7313-1',
            'dev-aiexe.infomina.ai' => 'SK-V1',
            'staging-aiexe.infomina.ai' => 'SK-N1',
        ], $parsed);
    }

    public function test_empty_or_null_yields_no_keys(): void
    {
        $this->assertSame([], SenangpayKeys::parse(null));
        $this->assertSame([], SenangpayKeys::parse('   '));
    }

    public function test_keys_may_contain_equals_signs(): void
    {
        $this->assertSame(['localhost' => 'abc=def=='], SenangpayKeys::parse('localhost=abc=def=='));
    }
}
