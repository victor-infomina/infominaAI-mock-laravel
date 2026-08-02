<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DnbSearchTest extends TestCase
{
    use RefreshDatabase;

    private string $casesPath;
    private ApiClient $apiClient;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casesPath = sys_get_temp_dir().'/dnb-mock-search-test-'.uniqid();
        mkdir($this->casesPath, 0777, true);
        config(['dnb_mock.cases_path' => $this->casesPath]);

        [$this->apiClient, $this->secret] = ApiClient::createWithSecret('be-app', null, 'dnb');

        $sgCase = $this->casesPath.'/acme-sg';
        mkdir($sgCase, 0777, true);
        file_put_contents($sgCase.'/meta.json', json_encode([
            'country' => 'singapore', 'regNo' => '201912345A', 'companyName' => 'Acme Pte Ltd',
        ]));

        $idCase = $this->casesPath.'/acme-id';
        mkdir($idCase, 0777, true);
        file_put_contents($idCase.'/meta.json', json_encode([
            'country' => 'indonesia', 'companyId' => 'ID12345', 'companyName' => 'Acme Indonesia',
        ]));
    }

    private function postXml(string $xml): TestResponse
    {
        return $this->call('POST', '/dnb', [], [], [], ['CONTENT_TYPE' => 'application/xml'], $xml);
    }

    private function wrapWithAuth(string $enquiryXml): string
    {
        return "<REQUEST><USER_ID>{$this->apiClient->key}</USER_ID><PASSWORD>{$this->secret}</PASSWORD>{$enquiryXml}</REQUEST>";
    }

    public function test_singapore_search_by_reg_no(): void
    {
        $xml = $this->wrapWithAuth('<ENQUIRY><PRODUCT>XCNS</PRODUCT><COMPANY_SEARCH_TYPE>REG</COMPANY_SEARCH_TYPE><COMPANY_SEARCH_VALUE>201912345A</COMPANY_SEARCH_VALUE></ENQUIRY>');

        $response = $this->postXml($xml);

        $response->assertOk();
        $response->assertSee('Acme Pte Ltd', false);
        $response->assertSee('201912345A', false);
    }

    public function test_singapore_search_by_reg_no_is_case_insensitive(): void
    {
        $xml = $this->wrapWithAuth('<ENQUIRY><PRODUCT>XCNS</PRODUCT><COMPANY_SEARCH_TYPE>reg</COMPANY_SEARCH_TYPE><COMPANY_SEARCH_VALUE>201912345A</COMPANY_SEARCH_VALUE></ENQUIRY>');

        $response = $this->postXml($xml);

        $response->assertOk();
        $response->assertSee('Acme Pte Ltd', false);
        $response->assertSee('201912345A', false);
    }

    public function test_singapore_search_by_name(): void
    {
        $xml = $this->wrapWithAuth('<ENQUIRY><PRODUCT>XCNS</PRODUCT><COMPANY_SEARCH_TYPE>NAME</COMPANY_SEARCH_TYPE><COMPANY_SEARCH_VALUE>acme</COMPANY_SEARCH_VALUE></ENQUIRY>');

        $response = $this->postXml($xml);

        $response->assertSee('Acme Pte Ltd', false);
    }

    public function test_indonesia_search_by_name(): void
    {
        $xml = $this->wrapWithAuth('<ENQUIRY><PRODUCT>XICNS</PRODUCT><COMPANY_NAME>acme</COMPANY_NAME></ENQUIRY>');

        $response = $this->postXml($xml);

        $response->assertSee('Acme Indonesia', false);
        $response->assertSee('ID12345', false);
    }

    public function test_no_match_returns_empty_list(): void
    {
        $xml = $this->wrapWithAuth('<ENQUIRY><PRODUCT>XCNS</PRODUCT><COMPANY_SEARCH_TYPE>NAME</COMPANY_SEARCH_TYPE><COMPANY_SEARCH_VALUE>unknown</COMPANY_SEARCH_VALUE></ENQUIRY>');

        $response = $this->postXml($xml);

        $response->assertOk();
        $response->assertDontSee('<CompanyB2b>', false);
    }

    public function test_rejects_missing_credentials(): void
    {
        $xml = '<REQUEST><ENQUIRY><PRODUCT>XCNS</PRODUCT><COMPANY_SEARCH_TYPE>REG</COMPANY_SEARCH_TYPE><COMPANY_SEARCH_VALUE>201912345A</COMPANY_SEARCH_VALUE></ENQUIRY></REQUEST>';

        $response = $this->postXml($xml);

        $response->assertStatus(401);
    }

    public function test_rejects_wrong_purpose_credentials(): void
    {
        [$wrongClient, $wrongSecret] = ApiClient::createWithSecret('be-app-gateway', null, 'gateway');
        $xml = "<REQUEST><USER_ID>{$wrongClient->key}</USER_ID><PASSWORD>{$wrongSecret}</PASSWORD><ENQUIRY><PRODUCT>XCNS</PRODUCT><COMPANY_SEARCH_TYPE>REG</COMPANY_SEARCH_TYPE><COMPANY_SEARCH_VALUE>201912345A</COMPANY_SEARCH_VALUE></ENQUIRY></REQUEST>";

        $response = $this->postXml($xml);

        $response->assertStatus(401);
    }
}
