<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderDocumentAndReportTest extends TestCase
{
    private string $casesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casesPath = sys_get_temp_dir().'/ssm-mock-order-test-'.uniqid();
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

    private function seedCaseWithReport(string $key): void
    {
        $dir = $this->casesPath.'/'.$key;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/meta.json', json_encode([
            'regNo' => '123456-A',
            'companyName' => 'Acme Sdn Bhd',
            'entityType' => 'company',
        ]));
        file_put_contents($dir.'/report.pdf', "%PDF-1.4\n% Mock PDF content for testing\n");
    }

    public function test_order_document_returns_completed_status_and_document_url(): void
    {
        $this->seedCaseWithReport('acme-co');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-order-document', ['requestRefNo' => 'acme-co']);

        $response->assertOk();
        $response->assertJsonPath('getOrderDocument.data.status', 'Completed');
        $this->assertStringContainsString('/reports/acme-co.pdf', $response->json('getOrderDocument.data.documentUrl'));
    }

    public function test_order_document_returns_404_for_unknown_ref(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-order-document', ['requestRefNo' => 'unknown']);

        $response->assertStatus(404);
    }

    public function test_report_pdf_is_served_without_auth_headers(): void
    {
        $this->seedCaseWithReport('acme-co');

        $response = $this->get('/reports/acme-co.pdf');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_report_pdf_returns_404_for_unknown_case(): void
    {
        $response = $this->get('/reports/unknown.pdf');

        $response->assertStatus(404);
    }
}
