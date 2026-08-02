<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AsiaverifyProfileTest extends TestCase
{
    private string $casesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casesPath = sys_get_temp_dir().'/asiaverify-mock-profile-test-'.uniqid();
        mkdir($this->casesPath, 0777, true);
        config(['asiaverify_mock.cases_path' => $this->casesPath]);

        $caseDir = $this->casesPath.'/nguyen-trading';
        mkdir($caseDir, 0777, true);
        file_put_contents($caseDir.'/meta.json', json_encode([
            'country' => 'VNM',
            'companyId' => '0101234567',
            'companyName' => 'Nguyen Trading Co',
        ]));
        file_put_contents($caseDir.'/profile.json', json_encode([
            'companyId' => '0101234567',
            'companyName' => 'Nguyen Trading Co',
            'status' => 'Active',
        ]));

        Cache::put('asiaverify_token:valid-token', true, 60);
    }

    public function test_returns_profile_for_known_company(): void
    {
        $response = $this->withHeaders(['token' => 'valid-token'])
            ->postJson('/VNM/basic', ['input' => '0101234567', 'language' => 'ALL']);

        $response->assertOk();
        $response->assertJsonPath('code', '200');
        $response->assertJsonPath('result.companyName', 'Nguyen Trading Co');
        $response->assertJsonPath('result.status', 'Active');
    }

    public function test_returns_404_code_for_unknown_company(): void
    {
        $response = $this->withHeaders(['token' => 'valid-token'])
            ->postJson('/VNM/basic', ['input' => 'unknown', 'language' => 'ALL']);

        $response->assertOk();
        $response->assertJsonPath('code', '404');
        $response->assertJsonPath('result', null);
    }

    public function test_rejects_missing_token(): void
    {
        $response = $this->postJson('/VNM/basic', ['input' => '0101234567']);

        $response->assertOk();
        $response->assertJsonPath('code', '401');
    }
}
