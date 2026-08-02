<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AsiaverifyEndToEndRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_search_and_profile_round_trip(): void
    {
        $casesPath = sys_get_temp_dir().'/asiaverify-mock-e2e-test-'.uniqid();
        mkdir($casesPath, 0777, true);
        config(['asiaverify_mock.cases_path' => $casesPath]);

        $caseDir = $casesPath.'/nguyen-trading';
        mkdir($caseDir, 0777, true);
        file_put_contents($caseDir.'/meta.json', json_encode([
            'country' => 'VNM',
            'companyId' => '0101234567',
            'companyName' => 'Nguyen Trading Co',
        ]));
        file_put_contents($caseDir.'/profile.json', json_encode([
            'companyId' => '0101234567',
            'companyName' => 'Nguyen Trading Co',
        ]));

        [$apiClient, $secret] = ApiClient::createWithSecret('be-app', null, 'asiaverify');

        $tokenResponse = $this->withHeaders([
            'Authorization' => $apiClient->key,
            'Sign' => $secret,
        ])->postJson('/token/create');
        $tokenResponse->assertJsonPath('code', '200');
        $token = $tokenResponse->json('result.token');

        $searchResponse = $this->withHeaders(['token' => $token])
            ->getJson('/VNM/search?keyword=Nguyen');
        $searchResponse->assertJsonPath('result.data.0.companyId', '0101234567');

        $profileResponse = $this->withHeaders(['token' => $token])
            ->postJson('/VNM/basic', ['input' => '0101234567']);
        $profileResponse->assertJsonPath('code', '200');
        $profileResponse->assertJsonPath('result.companyName', 'Nguyen Trading Co');
    }
}
