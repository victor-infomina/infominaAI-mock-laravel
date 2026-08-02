<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DnbEndToEndRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_then_profile_purchase_round_trip(): void
    {
        $casesPath = sys_get_temp_dir().'/dnb-mock-e2e-test-'.uniqid();
        mkdir($casesPath, 0777, true);
        config(['dnb_mock.cases_path' => $casesPath]);

        $caseDir = $casesPath.'/acme-sg';
        mkdir($caseDir, 0777, true);
        file_put_contents($caseDir.'/meta.json', json_encode([
            'country' => 'singapore', 'regNo' => '201912345A', 'companyName' => 'Acme Pte Ltd',
        ]));
        file_put_contents($caseDir.'/profile.xml', '<REPORT><CompanyB2b><CompanyName>Acme Pte Ltd</CompanyName></CompanyB2b></REPORT>');

        [$apiClient, $secret] = ApiClient::createWithSecret('be-app', null, 'dnb');
        $auth = "<USER_ID>{$apiClient->key}</USER_ID><PASSWORD>{$secret}</PASSWORD>";

        $searchXml = "<REQUEST>{$auth}<ENQUIRY><PRODUCT>XCNS</PRODUCT><COMPANY_SEARCH_TYPE>NAME</COMPANY_SEARCH_TYPE><COMPANY_SEARCH_VALUE>acme</COMPANY_SEARCH_VALUE></ENQUIRY></REQUEST>";
        $searchResponse = $this->call('POST', '/dnb', [], [], [], ['CONTENT_TYPE' => 'application/xml'], $searchXml);
        $searchResponse->assertSee('201912345A', false);

        $profileXml = "<REQUEST>{$auth}<ENQUIRY><PRODUCT>BCP</PRODUCT><SUBJECT_IDNO>201912345A</SUBJECT_IDNO></ENQUIRY></REQUEST>";
        $profileResponse = $this->call('POST', '/dnb', [], [], [], ['CONTENT_TYPE' => 'application/xml'], $profileXml);
        $profileResponse->assertSee('Acme Pte Ltd', false);
    }
}
