<?php

namespace Tests\Feature;

use App\Services\S3ObjectFetcher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeS3ObjectFetcher;
use Tests\TestCase;

class ImportSsmCasesCommandTest extends TestCase
{
    private string $casesPath;

    private string $csvPath;

    private string $idamanCsvPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casesPath = sys_get_temp_dir().'/import-cases-'.uniqid();
        $this->csvPath = sys_get_temp_dir().'/import-cases-'.uniqid().'.csv';
        $this->idamanCsvPath = sys_get_temp_dir().'/import-idaman-'.uniqid().'.csv';
        config([
            'ssm_mock.cases_path' => $this->casesPath,
            'ssm_mock.s3_bucket' => 'my-bucket',
            'ssm_mock.s3_region' => 'ap-southeast-5',
            'ssm_mock.remote_url' => 'https://remote.example.test',
            'ssm_mock.admin_sync_key' => 'remote-key',
            'ssm_mock.admin_sync_secret' => 'remote-secret',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->casesPath);
        @unlink($this->csvPath);
        @unlink($this->idamanCsvPath);

        parent::tearDown();
    }

    private function writeCsv(array $lines): void
    {
        file_put_contents($this->csvPath, implode("\n", array_merge(
            ['company_name,reg_no,entity_type,raw_s3_url,pdf_s3_url'],
            $lines,
        )));
    }

    private function writeIdamanCsv(array $lines): void
    {
        file_put_contents($this->idamanCsvPath, implode("\n", array_merge(
            ['reg_no,version_id,form,document_date,s3_url'],
            $lines,
        )));
    }

    private function idamanEnvelope(string $content): string
    {
        return json_encode(['getImage' => ['docContent' => base64_encode($content)]]);
    }

    private function fakeS3(array $objects): void
    {
        $this->app->bind(S3ObjectFetcher::class, fn () => new FakeS3ObjectFetcher($objects));
    }

    public function test_writes_each_row_as_a_local_case_folder(): void
    {
        $this->writeCsv([
            'Acme Sdn Bhd,123456-A,company,https://my-bucket.s3.ap-southeast-5.amazonaws.com/raw/acme.json,https://my-bucket.s3.ap-southeast-5.amazonaws.com/pdf/acme.pdf',
            'Beta Enterprise,SA0001,business,biz/beta.json,',
        ]);
        $this->fakeS3([
            'my-bucket/raw/acme.json' => '{"getCompProfile":{}}',
            'my-bucket/pdf/acme.pdf' => '%PDF acme',
            'my-bucket/biz/beta.json' => '{"getBizProfile":{}}',
        ]);

        $this->artisan('ssm:import-cases', ['csv' => $this->csvPath])
            ->expectsOutputToContain('Acme Sdn Bhd')
            ->expectsOutputToContain('Beta Enterprise')
            ->assertExitCode(0);

        $this->assertSame('{"getCompProfile":{}}', file_get_contents($this->casesPath.'/acme-sdn-bhd/company-profile.json'));
        $this->assertSame('%PDF acme', file_get_contents($this->casesPath.'/acme-sdn-bhd/report.pdf'));
        $this->assertSame(
            ['regNo' => '123456-A', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company'],
            json_decode(file_get_contents($this->casesPath.'/acme-sdn-bhd/meta.json'), true),
        );
        $this->assertSame('{"getBizProfile":{}}', file_get_contents($this->casesPath.'/beta-enterprise/business-profile.json'));
        $this->assertFileDoesNotExist($this->casesPath.'/beta-enterprise/report.pdf');
    }

    public function test_push_option_posts_each_case_to_the_remote_instead_of_writing_locally(): void
    {
        $this->writeCsv([
            'Acme Sdn Bhd,123456-A,company,raw/acme.json,',
        ]);
        $this->fakeS3(['my-bucket/raw/acme.json' => '{"getCompProfile":{}}']);

        $capturedZipPath = null;
        Http::fake(function ($request) use (&$capturedZipPath) {
            $bundle = collect($request->data())->firstWhere('name', 'bundle');
            $capturedZipPath = sys_get_temp_dir().'/captured-'.uniqid().'.zip';
            file_put_contents($capturedZipPath, $bundle['contents']);

            return Http::response(['caseKey' => 'acme-sdn-bhd', 'status' => 'synced'], 200);
        });

        $this->artisan('ssm:import-cases', ['csv' => $this->csvPath, '--push' => true])
            ->expectsOutputToContain('synced: acme-sdn-bhd')
            ->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertDirectoryDoesNotExist($this->casesPath.'/acme-sdn-bhd');

        $zip = new \ZipArchive;
        $zip->open($capturedZipPath);
        $this->assertNotFalse($zip->locateName('acme-sdn-bhd/meta.json'));
        $this->assertNotFalse($zip->locateName('acme-sdn-bhd/company-profile.json'));
        $zip->close();
    }

    public function test_reports_failed_rows_continues_and_exits_non_zero(): void
    {
        $this->writeCsv([
            'Missing Corp,1,company,raw/missing.json,',
            'Acme Sdn Bhd,123456-A,company,raw/acme.json,',
        ]);
        $this->fakeS3(['my-bucket/raw/acme.json' => '{"getCompProfile":{}}']);

        $this->artisan('ssm:import-cases', ['csv' => $this->csvPath])
            ->expectsOutputToContain('Missing Corp')
            ->assertExitCode(1);

        $this->assertFileExists($this->casesPath.'/acme-sdn-bhd/company-profile.json');
        $this->assertDirectoryDoesNotExist($this->casesPath.'/missing-corp');
    }

    public function test_push_failure_from_remote_is_reported_and_exits_non_zero(): void
    {
        $this->writeCsv(['Acme Sdn Bhd,123456-A,company,raw/acme.json,']);
        $this->fakeS3(['my-bucket/raw/acme.json' => '{"getCompProfile":{}}']);
        Http::fake(['https://remote.example.test/admin-api/cases' => Http::response(['error' => 'nope'], 401)]);

        $this->artisan('ssm:import-cases', ['csv' => $this->csvPath, '--push' => true])
            ->expectsOutputToContain('failed: 401')
            ->assertExitCode(1);
    }

    public function test_invalid_csv_fails_before_touching_s3(): void
    {
        file_put_contents($this->csvPath, "company_name,reg_no\nAcme,1\n");
        $this->fakeS3([]);

        $this->artisan('ssm:import-cases', ['csv' => $this->csvPath])
            ->expectsOutputToContain('entity_type')
            ->assertExitCode(1);
    }

    public function test_missing_csv_file_fails(): void
    {
        $this->artisan('ssm:import-cases', ['csv' => '/nonexistent/file.csv'])
            ->assertExitCode(1);
    }

    public function test_idaman_option_adds_documents_to_the_matching_case(): void
    {
        $this->writeCsv([
            'Acme Sdn Bhd,123456-A,company,raw/acme.json,',
            'Beta Enterprise,SA0001,business,biz/beta.json,',
        ]);
        $this->writeIdamanCsv([
            '123456-A,V1,557,2024-01-01,idaman/acme-v1.json',
            '123456-A,V2,24,2023-06-30,idaman/acme-v2.json',
        ]);
        $this->fakeS3([
            'my-bucket/raw/acme.json' => '{"getCompProfile":{}}',
            'my-bucket/biz/beta.json' => '{"getBizProfile":{}}',
            'my-bucket/idaman/acme-v1.json' => $this->idamanEnvelope('tiff-v1'),
            'my-bucket/idaman/acme-v2.json' => $this->idamanEnvelope('tiff-v2'),
        ]);

        $this->artisan('ssm:import-cases', ['csv' => $this->csvPath, '--idaman' => $this->idamanCsvPath])
            ->expectsOutputToContain('2 idaman document(s)')
            ->assertExitCode(0);

        $list = json_decode(file_get_contents($this->casesPath.'/acme-sdn-bhd/idaman/list.json'), true);
        $this->assertSame(
            [
                ['verId' => 'V1', 'formType' => '557', 'documentDate' => '20240101', 'companyNo' => '123456-A'],
                ['verId' => 'V2', 'formType' => '24', 'documentDate' => '20230630', 'companyNo' => '123456-A'],
            ],
            array_map(fn ($e) => ['verId' => $e['verId'], 'formType' => $e['formType'], 'documentDate' => $e['documentDate'], 'companyNo' => $e['companyNo']], $list),
        );
        $this->assertSame('tiff-v1', file_get_contents($this->casesPath.'/acme-sdn-bhd/idaman/V1.tiff'));
        $this->assertSame('tiff-v2', file_get_contents($this->casesPath.'/acme-sdn-bhd/idaman/V2.tiff'));
        $this->assertDirectoryDoesNotExist($this->casesPath.'/beta-enterprise/idaman');
    }

    public function test_idaman_rows_with_no_matching_profile_row_are_warned_and_skipped(): void
    {
        $this->writeCsv(['Acme Sdn Bhd,123456-A,company,raw/acme.json,']);
        $this->writeIdamanCsv(['UNKNOWN-1,V1,557,2024-01-01,idaman/unknown.json']);
        $this->fakeS3(['my-bucket/raw/acme.json' => '{"getCompProfile":{}}']);

        $this->artisan('ssm:import-cases', ['csv' => $this->csvPath, '--idaman' => $this->idamanCsvPath])
            ->expectsOutputToContain('UNKNOWN-1')
            ->assertExitCode(0);

        $this->assertFileExists($this->casesPath.'/acme-sdn-bhd/company-profile.json');
        $this->assertDirectoryDoesNotExist($this->casesPath.'/acme-sdn-bhd/idaman');
    }

    public function test_idaman_documents_are_included_in_the_pushed_bundle(): void
    {
        $this->writeCsv(['Acme Sdn Bhd,123456-A,company,raw/acme.json,']);
        $this->writeIdamanCsv(['123456-A,V1,557,2024-01-01,idaman/acme-v1.json']);
        $this->fakeS3([
            'my-bucket/raw/acme.json' => '{"getCompProfile":{}}',
            'my-bucket/idaman/acme-v1.json' => $this->idamanEnvelope('tiff-v1'),
        ]);

        $capturedZipPath = null;
        Http::fake(function ($request) use (&$capturedZipPath) {
            $bundle = collect($request->data())->firstWhere('name', 'bundle');
            $capturedZipPath = sys_get_temp_dir().'/captured-'.uniqid().'.zip';
            file_put_contents($capturedZipPath, $bundle['contents']);

            return Http::response(['caseKey' => 'acme-sdn-bhd', 'status' => 'synced'], 200);
        });

        $this->artisan('ssm:import-cases', ['csv' => $this->csvPath, '--idaman' => $this->idamanCsvPath, '--push' => true])
            ->assertExitCode(0);

        $zip = new \ZipArchive;
        $zip->open($capturedZipPath);
        $this->assertNotFalse($zip->locateName('acme-sdn-bhd/idaman/list.json'));
        $this->assertSame('tiff-v1', $zip->getFromName('acme-sdn-bhd/idaman/V1.tiff'));
        $zip->close();
    }

    public function test_invalid_idaman_csv_fails_before_touching_s3(): void
    {
        $this->writeCsv(['Acme Sdn Bhd,123456-A,company,raw/acme.json,']);
        file_put_contents($this->idamanCsvPath, "reg_no,version_id\n123456-A,V1\n");
        $this->fakeS3([]);

        $this->artisan('ssm:import-cases', ['csv' => $this->csvPath, '--idaman' => $this->idamanCsvPath])
            ->expectsOutputToContain('document_date')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->casesPath.'/acme-sdn-bhd');
    }

    public function test_idaman_download_failure_is_reported_per_row_and_other_rows_still_run(): void
    {
        $this->writeCsv([
            'Acme Sdn Bhd,123456-A,company,raw/acme.json,',
            'Beta Enterprise,SA0001,business,biz/beta.json,',
        ]);
        $this->writeIdamanCsv(['123456-A,V1,557,2024-01-01,idaman/missing.json']);
        $this->fakeS3([
            'my-bucket/raw/acme.json' => '{"getCompProfile":{}}',
            'my-bucket/biz/beta.json' => '{"getBizProfile":{}}',
        ]);

        $this->artisan('ssm:import-cases', ['csv' => $this->csvPath, '--idaman' => $this->idamanCsvPath])
            ->expectsOutputToContain('idaman/missing.json')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->casesPath.'/acme-sdn-bhd');
        $this->assertFileExists($this->casesPath.'/beta-enterprise/business-profile.json');
    }
}
