<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CaseUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $casesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casesPath = sys_get_temp_dir().'/ssm-mock-case-upload-test-'.uniqid();
        mkdir($this->casesPath, 0777, true);
        config(['ssm_mock.cases_path' => $this->casesPath]);
    }

    private function authHeaders(string $purpose = 'admin_sync'): array
    {
        [$apiClient, $secret] = ApiClient::createWithSecret('local-sync-tool', null, $purpose);

        return [
            'x-Gateway-APIKey' => $apiClient->key,
            'x-Gateway-APISecret' => $secret,
        ];
    }

    private function makeZip(string $topDir, array $files): string
    {
        $zipPath = sys_get_temp_dir().'/'.uniqid('case-', true).'.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);

        foreach ($files as $relativePath => $content) {
            $zip->addFromString("{$topDir}/{$relativePath}", $content);
        }

        $zip->close();

        return $zipPath;
    }

    public function test_uploads_and_extracts_a_valid_bundle(): void
    {
        $zipPath = $this->makeZip('hicom-holdings-berhad', [
            'meta.json' => json_encode(['regNo' => '191001000005', 'companyName' => 'Hicom Holdings Berhad', 'entityType' => 'company']),
            'company-profile.json' => '{"getCompProfile":{}}',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->post('/admin-api/cases', ['bundle' => new UploadedFile($zipPath, 'case.zip', 'application/zip', null, true)]);

        $response->assertOk();
        $response->assertJson(['caseKey' => 'hicom-holdings-berhad', 'status' => 'synced']);
        $this->assertFileExists("{$this->casesPath}/hicom-holdings-berhad/meta.json");
        $this->assertFileExists("{$this->casesPath}/hicom-holdings-berhad/company-profile.json");
    }

    public function test_overwrites_an_existing_case_of_the_same_key(): void
    {
        mkdir("{$this->casesPath}/hicom-holdings-berhad", 0777, true);
        file_put_contents("{$this->casesPath}/hicom-holdings-berhad/stale.txt", "old data");

        $zipPath = $this->makeZip('hicom-holdings-berhad', [
            'meta.json' => json_encode(['regNo' => '191001000005', 'companyName' => 'Hicom Holdings Berhad', 'entityType' => 'company']),
        ]);

        $this->withHeaders($this->authHeaders())
            ->post('/admin-api/cases', ['bundle' => new UploadedFile($zipPath, 'case.zip', 'application/zip', null, true)])
            ->assertOk();

        $this->assertFileDoesNotExist("{$this->casesPath}/hicom-holdings-berhad/stale.txt");
        $this->assertFileExists("{$this->casesPath}/hicom-holdings-berhad/meta.json");
    }

    public function test_rejects_gateway_purpose_token(): void
    {
        $zipPath = $this->makeZip('acme', ['meta.json' => '{}']);

        $response = $this->withHeaders($this->authHeaders('gateway'))
            ->post('/admin-api/cases', ['bundle' => new UploadedFile($zipPath, 'case.zip', 'application/zip', null, true)]);

        $response->assertStatus(401);
    }

    public function test_rejects_bundle_missing_meta_json(): void
    {
        $zipPath = $this->makeZip('acme', ['company-profile.json' => '{}']);

        $response = $this->withHeaders($this->authHeaders())
            ->post('/admin-api/cases', ['bundle' => new UploadedFile($zipPath, 'case.zip', 'application/zip', null, true)]);

        $response->assertStatus(422);
    }

    public function test_rejects_zip_entry_that_escapes_the_case_directory(): void
    {
        $zipPath = sys_get_temp_dir().'/'.uniqid('case-', true).'.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('acme/meta.json', json_encode(['regNo' => 'x', 'companyName' => 'x', 'entityType' => 'company']));
        $zip->addFromString('acme/../../../etc/evil.txt', 'malicious');
        $zip->close();

        $response = $this->withHeaders($this->authHeaders())
            ->post('/admin-api/cases', ['bundle' => new UploadedFile($zipPath, 'case.zip', 'application/zip', null, true)]);

        $response->assertStatus(422);
    }
}
