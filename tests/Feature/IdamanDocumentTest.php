<?php

namespace Tests\Feature;

use Tests\TestCase;

class IdamanDocumentTest extends TestCase
{
    private string $casesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casesPath = sys_get_temp_dir().'/ssm-mock-idaman-doc-test-'.uniqid();
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

    private function seedCaseWithDocument(string $key, string $regNo, string $verId, string $content): void
    {
        $dir = $this->casesPath.'/'.$key;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/meta.json', json_encode([
            'regNo' => $regNo,
            'companyName' => 'Acme Sdn Bhd',
            'entityType' => 'company',
        ]));

        $idamanDir = $dir.'/idaman';
        mkdir($idamanDir, 0777, true);
        file_put_contents($idamanDir.'/list.json', json_encode([
            ['verId' => $verId, 'formType' => '557', 'dateFiler' => '2024-01-01'],
        ]));
        file_put_contents($idamanDir.'/'.$verId.'.tiff', $content);
    }

    public function test_returns_base64_content_for_matching_doc(): void
    {
        $this->seedCaseWithDocument('acme-co', '123456-A', 'V1', 'raw-tiff-bytes');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-image', ['regNo' => '123456-A', 'verId' => 'V1']);

        $response->assertOk();
        $this->assertSame('raw-tiff-bytes', base64_decode($response->json('getImage.docContent')));
    }

    public function test_returns_error_msg_when_reg_no_does_not_match(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-image', ['regNo' => 'unknown', 'verId' => 'V1']);

        $response->assertOk();
        $response->assertJsonPath('getImage.errorMsg', 'not found');
    }

    public function test_returns_error_msg_when_ver_id_does_not_match(): void
    {
        $this->seedCaseWithDocument('acme-co', '123456-A', 'V1', 'raw-tiff-bytes');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-image', ['regNo' => '123456-A', 'verId' => 'unknown-ver']);

        $response->assertOk();
        $response->assertJsonPath('getImage.errorMsg', 'not found');
    }

    public function test_returns_error_msg_when_list_entry_exists_but_file_is_missing(): void
    {
        $dir = $this->casesPath.'/acme-co';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/meta.json', json_encode([
            'regNo' => '123456-A',
            'companyName' => 'Acme Sdn Bhd',
            'entityType' => 'company',
        ]));
        $idamanDir = $dir.'/idaman';
        mkdir($idamanDir, 0777, true);
        file_put_contents($idamanDir.'/list.json', json_encode([
            ['verId' => 'V1', 'formType' => '557', 'dateFiler' => '2024-01-01'],
        ]));

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-image', ['regNo' => '123456-A', 'verId' => 'V1']);

        $response->assertOk();
        $response->assertJsonPath('getImage.errorMsg', 'not found');
    }

    public function test_rejects_request_without_gateway_credentials(): void
    {
        $response = $this->postJson('/get-image', ['regNo' => '123456-A', 'verId' => 'V1']);

        $response->assertStatus(401);
    }
}
