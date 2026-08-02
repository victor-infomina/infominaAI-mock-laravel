<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DnbProfileTest extends TestCase
{
    use RefreshDatabase;

    private string $casesPath;
    private ApiClient $apiClient;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casesPath = sys_get_temp_dir().'/dnb-mock-profile-test-'.uniqid();
        mkdir($this->casesPath, 0777, true);
        config(['dnb_mock.cases_path' => $this->casesPath]);

        [$this->apiClient, $this->secret] = ApiClient::createWithSecret('be-app', null, 'dnb');

        $sgCase = $this->casesPath.'/acme-sg';
        mkdir($sgCase, 0777, true);
        file_put_contents($sgCase.'/meta.json', json_encode([
            'country' => 'singapore', 'regNo' => '201912345A', 'companyName' => 'Acme Pte Ltd',
        ]));
        file_put_contents($sgCase.'/profile.xml', '<REPORT><CompanyB2b><CompanyName>Acme Pte Ltd</CompanyName></CompanyB2b></REPORT>');

        $idCase = $this->casesPath.'/acme-id';
        mkdir($idCase, 0777, true);
        file_put_contents($idCase.'/meta.json', json_encode([
            'country' => 'indonesia', 'companyId' => 'ID12345', 'companyName' => 'Acme Indonesia',
        ]));
        file_put_contents($idCase.'/profile.xml', '<REPORT><COMPANY><COMPANY_NAME>Acme Indonesia</COMPANY_NAME></COMPANY></REPORT>');
    }

    private function postXml(string $xml): TestResponse
    {
        return $this->call('POST', '/dnb', [], [], [], ['CONTENT_TYPE' => 'application/xml'], $xml);
    }

    private function wrapWithAuth(string $enquiryXml): string
    {
        return "<REQUEST><USER_ID>{$this->apiClient->key}</USER_ID><PASSWORD>{$this->secret}</PASSWORD>{$enquiryXml}</REQUEST>";
    }

    public function test_singapore_profile_purchase_returns_captured_xml(): void
    {
        $xml = $this->wrapWithAuth('<ENQUIRY><PRODUCT>BCP</PRODUCT><SUBJECT_IDNO>201912345A</SUBJECT_IDNO></ENQUIRY>');

        $response = $this->postXml($xml);

        $response->assertOk();
        $response->assertSee('Acme Pte Ltd', false);
    }

    public function test_indonesia_profile_purchase_returns_captured_xml(): void
    {
        $xml = $this->wrapWithAuth('<ENQUIRY><PRODUCT>XICDS</PRODUCT><COMPANY_ID>ID12345</COMPANY_ID></ENQUIRY>');

        $response = $this->postXml($xml);

        $response->assertOk();
        $response->assertSee('Acme Indonesia', false);
    }

    public function test_unknown_reg_no_returns_empty_report(): void
    {
        $xml = $this->wrapWithAuth('<ENQUIRY><PRODUCT>BCP</PRODUCT><SUBJECT_IDNO>unknown</SUBJECT_IDNO></ENQUIRY>');

        $response = $this->postXml($xml);

        $response->assertOk();
        $response->assertSee('<REPORT></REPORT>', false);
    }

    public function test_unknown_company_id_returns_empty_report(): void
    {
        $xml = $this->wrapWithAuth('<ENQUIRY><PRODUCT>XICDS</PRODUCT><COMPANY_ID>unknown</COMPANY_ID></ENQUIRY>');

        $response = $this->postXml($xml);

        $response->assertOk();
        $response->assertSee('<REPORT></REPORT>', false);
    }
}
