<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProfileEndpointsTest extends TestCase
{
    private string $casesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casesPath = sys_get_temp_dir().'/ssm-mock-profile-test-'.uniqid();
        mkdir($this->casesPath, 0777, true);
        config([
            'ssm_mock.cases_path' => $this->casesPath,
            'ssm_mock.api_key' => 'test-key',
            'ssm_mock.api_secret' => 'test-secret',
        ]);
    }

    private function authHeaders(): array
    {
        return [
            'x-Gateway-APIKey' => 'test-key',
            'x-Gateway-APISecret' => 'test-secret',
        ];
    }

    private function seedCase(string $key, array $meta, string $fixtureFile, array $fixtureData): void
    {
        $dir = $this->casesPath.'/'.$key;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/meta.json', json_encode($meta));
        file_put_contents($dir.'/'.$fixtureFile, json_encode($fixtureData));
    }

    public function test_company_profile_returns_fixture_with_rewritten_request_ref_no(): void
    {
        $this->seedCase('acme-co', [
            'regNo' => '123456-A',
            'companyName' => 'Acme Sdn Bhd',
            'entityType' => 'company',
        ], 'company-profile.json', [
            'getCompProfile' => [
                'errorMsg' => null,
                'requestRefNo' => 'ORIGINAL-REF-FROM-CAPTURE',
                'rocCompanyInfo' => ['companyName' => 'Acme Sdn Bhd', 'companyNo' => '123456-A'],
            ],
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/v2/get-company-profile-document', ['regNo' => '123456-A']);

        $response->assertOk();
        $response->assertJsonPath('getCompProfile.requestRefNo', 'acme-co');
        $response->assertJsonPath('getCompProfile.rocCompanyInfo.companyName', 'Acme Sdn Bhd');
    }

    public function test_business_profile_returns_fixture_with_rewritten_request_ref_no(): void
    {
        $this->seedCase('beta-biz', [
            'regNo' => 'IP0238873',
            'companyName' => 'Beta Enterprise',
            'entityType' => 'business',
        ], 'business-profile.json', [
            'getBizProfile' => [
                'errorMsg' => null,
                'requestRefNo' => 'ORIGINAL-REF-FROM-CAPTURE',
                'robBusinessInfo' => ['registrationName' => 'Beta Enterprise', 'registrationNo' => 'IP0238873'],
            ],
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/v2/get-bizprofile-document', ['regNo' => 'IP0238873']);

        $response->assertOk();
        $response->assertJsonPath('getBizProfile.requestRefNo', 'beta-biz');
    }

    public function test_llp_profile_returns_fixture_with_rewritten_request_ref_no(): void
    {
        $this->seedCase('gamma-llp', [
            'regNo' => 'LLP0012345',
            'companyName' => 'Gamma LLP',
            'entityType' => 'llp',
        ], 'llp-profile.json', [
            'getLlpCurrentProfile' => [
                'requestRefNo' => 'ORIGINAL-REF-FROM-CAPTURE',
                'llpCurrentProfile' => ['llpBasicProfile' => ['llpName' => 'Gamma LLP', 'llpNo' => 'LLP0012345']],
            ],
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/v2/get-llp-current-profile', ['regNo' => 'LLP0012345']);

        $response->assertOk();
        $response->assertJsonPath('getLlpCurrentProfile.requestRefNo', 'gamma-llp');
    }

    public function test_unknown_reg_no_returns_404(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/v2/get-company-profile-document', ['regNo' => 'unknown']);

        $response->assertStatus(404);
    }

    public function test_wrong_entity_type_endpoint_returns_404(): void
    {
        $this->seedCase('acme-co', [
            'regNo' => '123456-A',
            'companyName' => 'Acme Sdn Bhd',
            'entityType' => 'company',
        ], 'company-profile.json', ['getCompProfile' => ['requestRefNo' => 'X']]);

        // Same regNo, but hitting the business endpoint — case is typed "company", so no match.
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/v2/get-bizprofile-document', ['regNo' => '123456-A']);

        $response->assertStatus(404);
    }
}
