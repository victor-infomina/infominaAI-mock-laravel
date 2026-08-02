<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AsiaverifySearchTest extends TestCase
{
    private string $casesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casesPath = sys_get_temp_dir().'/asiaverify-mock-search-test-'.uniqid();
        mkdir($this->casesPath, 0777, true);
        config(['asiaverify_mock.cases_path' => $this->casesPath]);

        $caseDir = $this->casesPath.'/nguyen-trading';
        mkdir($caseDir, 0777, true);
        file_put_contents($caseDir.'/meta.json', json_encode([
            'country' => 'VNM',
            'companyId' => '0101234567',
            'companyName' => 'Nguyen Trading Co',
        ]));

        Cache::put('asiaverify_token:valid-token', true, 60);
    }

    public function test_finds_case_by_keyword(): void
    {
        $response = $this->withHeaders(['token' => 'valid-token'])
            ->getJson('/VNM/search?keyword=Nguyen');

        $response->assertOk();
        $response->assertJsonPath('code', '200');
        $response->assertJsonPath('result.data.0.companyName', 'Nguyen Trading Co');
        $response->assertJsonPath('result.data.0.companyId', '0101234567');
        $response->assertJsonPath('result.total', 1);
    }

    public function test_no_match_returns_empty_result(): void
    {
        $response = $this->withHeaders(['token' => 'valid-token'])
            ->getJson('/VNM/search?keyword=unknown');

        $response->assertOk();
        $response->assertJsonPath('code', '200');
        $response->assertJsonPath('result.total', 0);
    }

    public function test_country_mismatch_returns_no_results(): void
    {
        $response = $this->withHeaders(['token' => 'valid-token'])
            ->getJson('/THA/search?keyword=Nguyen');

        $response->assertJsonPath('result.total', 0);
    }

    public function test_rejects_missing_token(): void
    {
        $response = $this->getJson('/VNM/search?keyword=Nguyen');

        $response->assertOk();
        $response->assertJsonPath('code', '401');
    }

    public function test_rejects_invalid_token(): void
    {
        $response = $this->withHeaders(['token' => 'bogus'])
            ->getJson('/VNM/search?keyword=Nguyen');

        $response->assertJsonPath('code', '401');
    }
}
