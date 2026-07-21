<?php

namespace Tests\Feature;

use Tests\TestCase;

class IdamanListTest extends TestCase
{
    private string $casesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casesPath = sys_get_temp_dir().'/ssm-mock-idaman-list-test-'.uniqid();
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

    private function seedCase(string $key, array $meta): void
    {
        $dir = $this->casesPath.'/'.$key;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/meta.json', json_encode($meta));
    }

    private function seedIdamanList(string $key, array $documents): void
    {
        $dir = $this->casesPath.'/'.$key.'/idaman';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/list.json', json_encode($documents));
    }

    public function test_returns_document_list_for_matching_reg_no(): void
    {
        $this->seedCase('acme-co', ['regNo' => '123456-A', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);
        $this->seedIdamanList('acme-co', [
            ['verId' => 'V1', 'formType' => '557', 'dateFiler' => '2024-01-01'],
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-image-list', ['regNo' => '123456-A']);

        $response->assertOk();
        $response->assertJsonPath('getImageView.documentInfos.documentInfos.0.verId', 'V1');
    }

    public function test_returns_empty_document_list_when_list_json_is_empty_array(): void
    {
        $this->seedCase('acme-co', ['regNo' => '123456-A', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);
        $this->seedIdamanList('acme-co', []);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-image-list', ['regNo' => '123456-A']);

        $response->assertOk();
        $response->assertJsonPath('getImageView.documentInfos.documentInfos', []);
    }

    public function test_returns_error_msg_when_reg_no_does_not_match_any_case(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-image-list', ['regNo' => 'unknown']);

        $response->assertOk();
        $response->assertJsonPath('getImageView.errorMsg', 'not found');
    }

    public function test_returns_error_msg_when_case_has_no_idaman_folder(): void
    {
        $this->seedCase('acme-co', ['regNo' => '123456-A', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-image-list', ['regNo' => '123456-A']);

        $response->assertOk();
        $response->assertJsonPath('getImageView.errorMsg', 'not found');
    }

    public function test_matches_reg_no_regardless_of_entity_type(): void
    {
        $this->seedCase('beta-biz', ['regNo' => 'IP0238873', 'companyName' => 'Beta Enterprise', 'entityType' => 'business']);
        $this->seedIdamanList('beta-biz', [
            ['verId' => 'V2', 'formType' => '9', 'dateFiler' => '2023-05-01'],
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/get-image-list', ['regNo' => 'IP0238873']);

        $response->assertOk();
        $response->assertJsonPath('getImageView.documentInfos.documentInfos.0.verId', 'V2');
    }

    public function test_rejects_request_without_gateway_credentials(): void
    {
        $response = $this->postJson('/get-image-list', ['regNo' => '123456-A']);

        $response->assertStatus(401);
    }
}
