<?php

namespace Tests\Feature;

use App\Services\S3ObjectFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeS3ObjectFetcher;
use Tests\TestCase;

class ImportSsmInvolvementsCommandTest extends TestCase
{
    private const PRODUCTION = [
        1060 => 'director-ctc-en', 1062 => 'director-ctc-my', 1059 => 'director-plain-en', 1061 => 'director-plain-my',
        1057 => 'shareholder-ctc-en', 1055 => 'shareholder-ctc-my', 1058 => 'shareholder-plain-en', 1056 => 'shareholder-plain-my',
        1053 => 'secretary-ctc-en', 1051 => 'secretary-ctc-my', 1054 => 'secretary-plain-en', 1052 => 'secretary-plain-my',
    ];

    private string $root;

    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/import-involvements-'.uniqid();
        $this->csvPath = sys_get_temp_dir().'/import-involvements-'.uniqid().'.csv';
        config([
            'ssm_gateway_mock.involvements_path' => $this->root,
            'ssm_mock.s3_bucket' => 'sample-bucket',
            'ssm_mock.s3_region' => 'ap-southeast-5',
            'ssm_mock.remote_url' => 'https://remote.example.test',
            'ssm_mock.admin_sync_key' => 'remote-key',
            'ssm_mock.admin_sync_secret' => 'remote-secret',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        @unlink($this->csvPath);
        parent::tearDown();
    }

    /** @param list<array{0: string, 1: int, 2: string}> $rows [IC, production variant, S3 key] */
    private function writeCsv(array $rows): void
    {
        $handle = fopen($this->csvPath, 'w');
        fputcsv($handle, ['identification_type', 'identification_no', 'subject_name', 'variant_id', 'pdf_s3_key'], ',', '"', '');
        foreach ($rows as [$ic, $variant, $key]) {
            fputcsv($handle, ['MK', $ic, 'NAME '.$ic, (string) $variant, $key], ',', '"', '');
        }
        fclose($handle);
    }

    private function fetcher(array $objects): FakeS3ObjectFetcher
    {
        $fake = new FakeS3ObjectFetcher($objects);
        $this->app->instance(S3ObjectFetcher::class, $fake);

        return $fake;
    }

    private function countingFetcher(): object
    {
        $fake = new class implements S3ObjectFetcher
        {
            public int $calls = 0;

            public function fetch(string $bucket, string $key): string
            {
                $this->calls++;

                return '%PDF-1.4 '.$key;
            }
        };
        $this->app->instance(S3ObjectFetcher::class, $fake);

        return $fake;
    }

    private function seedOld(string $ic, array $pdfs, ?string $default = null): void
    {
        mkdir($this->root.'/'.$ic, 0777, true);
        file_put_contents($this->root.'/'.$ic.'/meta.json', json_encode(['idType' => 'MK', 'idValue' => $ic, 'name' => 'OLD']));
        foreach ($pdfs as $identity => $bytes) {
            file_put_contents($this->root.'/'.$ic.'/'.$identity.'.pdf', $bytes);
        }
        if ($default !== null) {
            file_put_contents($this->root.'/default.json', json_encode(['idValue' => $default]));
        }
    }

    private function files(string $ic): array
    {
        $files = array_values(array_diff(scandir($this->root.'/'.$ic), ['.', '..']));
        sort($files);

        return $files;
    }

    public function test_failed_row_preserves_previous_bundle_and_default(): void
    {
        $this->seedOld('000123456789', ['director-plain-en' => '%PDF-1.4 old'], '000123456789');
        $oldPdf = file_get_contents($this->root.'/000123456789/director-plain-en.pdf');
        $oldMarker = file_get_contents($this->root.'/default.json');
        $this->writeCsv([['000123456789', 1059, 'ssm/one.pdf'], ['000123456789', 1060, 'ssm/missing.pdf'], ['900101015555', 1053, 'ssm/two.pdf']]);
        $this->fetcher([
            'sample-bucket/ssm/one.pdf' => '%PDF-1.4 director',
            'sample-bucket/ssm/two.pdf' => '%PDF-1.4 secretary',
        ]);

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789'])
            ->expectsOutputToContain('CSV row 3:')
            ->expectsOutputToContain('000123456789: NOT published')
            ->assertExitCode(1);

        $this->assertSame($oldPdf, file_get_contents($this->root.'/000123456789/director-plain-en.pdf'));
        $this->assertSame(['director-plain-en.pdf', 'meta.json'], $this->files('000123456789'));
        $this->assertSame($oldMarker, file_get_contents($this->root.'/default.json'));
        $this->assertSame('%PDF-1.4 secretary', file_get_contents($this->root.'/900101015555/secretary-ctc-en.pdf'));
    }

    public function test_imports_all_twelve_distinct_samples_and_sets_default(): void
    {
        $objects = [];
        $rows = [];
        foreach (self::PRODUCTION as $variant => $identity) {
            $rows[] = ['000123456789', $variant, "ssm/{$variant}.pdf"];
            $objects["sample-bucket/ssm/{$variant}.pdf"] = "%PDF-1.4 {$identity}";
        }
        $this->writeCsv($rows);
        $this->fetcher($objects);

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789'])
            ->expectsOutputToContain('000123456789: published as default (imported: director-ctc-en, director-ctc-my, director-plain-en, director-plain-my, secretary-ctc-en, secretary-ctc-my, secretary-plain-en, secretary-plain-my, shareholder-ctc-en, shareholder-ctc-my, shareholder-plain-en, shareholder-plain-my; missing: none)')
            ->assertExitCode(0);

        foreach (self::PRODUCTION as $identity) {
            $this->assertSame("%PDF-1.4 {$identity}", file_get_contents($this->root."/000123456789/{$identity}.pdf"));
        }
        $this->assertSame(
            ['idType' => 'MK', 'idValue' => '000123456789', 'name' => 'NAME 000123456789'],
            json_decode(file_get_contents($this->root.'/000123456789/meta.json'), true),
        );
        $this->assertSame(['idValue' => '000123456789'], json_decode(file_get_contents($this->root.'/default.json'), true));
    }

    public function test_preflight_failure_fetches_nothing(): void
    {
        $this->writeCsv([['000123456789', 1059, 'ssm/a.pdf'], ['000123456789', 1059, 'ssm/b.pdf']]);
        $fetcher = $this->countingFetcher();

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789'])
            ->expectsOutputToContain('duplicate IC/variant pair')
            ->assertExitCode(1);

        $this->assertSame(0, $fetcher->calls);
        $this->assertDirectoryDoesNotExist($this->root);
    }

    public function test_requires_default(): void
    {
        $this->writeCsv([['000123456789', 1059, 'ssm/a.pdf']]);
        $fetcher = $this->countingFetcher();

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath])
            ->expectsOutputToContain('--default is required')
            ->assertExitCode(1);

        $this->assertSame(0, $fetcher->calls);
    }

    public function test_requires_s3_configuration_before_fetching(): void
    {
        config(['ssm_mock.s3_bucket' => null]);
        $this->writeCsv([['000123456789', 1059, 'ssm/a.pdf']]);
        $fetcher = $this->countingFetcher();

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789'])
            ->expectsOutputToContain('SSM_S3_BUCKET')
            ->assertExitCode(1);

        $this->assertSame(0, $fetcher->calls);
    }

    public function test_reimport_replaces_rather_than_merges(): void
    {
        $this->seedOld('000123456789', ['director-plain-en' => '%PDF-1.4 old', 'director-plain-my' => '%PDF-1.4 old my']);
        $this->writeCsv([['000123456789', 1053, 'ssm/new.pdf']]);
        $this->fetcher(['sample-bucket/ssm/new.pdf' => '%PDF-1.4 new']);

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789'])->assertExitCode(0);

        $this->assertSame(['meta.json', 'secretary-ctc-en.pdf'], $this->files('000123456789'));
    }

    public function test_unrelated_ic_failure_does_not_block_default(): void
    {
        $this->seedOld('900101015555', ['director-plain-en' => '%PDF-1.4 kept']);
        $this->writeCsv([['000123456789', 1059, 'ssm/ok.pdf'], ['900101015555', 1059, 'ssm/missing.pdf']]);
        $this->fetcher(['sample-bucket/ssm/ok.pdf' => '%PDF-1.4 ok']);

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789'])
            ->expectsOutputToContain('900101015555: NOT published')
            ->assertExitCode(1);

        $this->assertSame(['idValue' => '000123456789'], json_decode(file_get_contents($this->root.'/default.json'), true));
        $this->assertSame('%PDF-1.4 kept', file_get_contents($this->root.'/900101015555/director-plain-en.pdf'));
    }

    public function test_reports_partial_default_coverage(): void
    {
        $this->writeCsv([['000123456789', 1059, 'ssm/a.pdf']]);
        $this->fetcher(['sample-bucket/ssm/a.pdf' => '%PDF-1.4 a']);

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789'])
            ->expectsOutputToContain('imported: director-plain-en; missing: director-ctc-en, director-ctc-my, director-plain-my, secretary-ctc-en,')
            ->assertExitCode(0);
    }

    public function test_unsupported_rows_only_warn(): void
    {
        $this->writeCsv([['000123456789', 1059, 'ssm/a.pdf'], ['000123456789', 1045, 'ssm/business.pdf']]);
        $this->fetcher(['sample-bucket/ssm/a.pdf' => '%PDF-1.4 a']);

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789'])
            ->expectsOutputToContain('unsupported production variant 1045; skipped')
            ->assertExitCode(0);
    }

    public function test_non_pdf_object_fails_its_row(): void
    {
        $this->writeCsv([['000123456789', 1059, 'ssm/a.pdf']]);
        $this->fetcher(['sample-bucket/ssm/a.pdf' => '<Error>AccessDenied</Error>']);

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789'])
            ->expectsOutputToContain('CSV row 2: 000123456789 director-plain-en (ssm/a.pdf): S3 object is not a PDF')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->root.'/000123456789');
        $this->assertFileDoesNotExist($this->root.'/default.json');
    }

    public function test_push_uploads_each_ic_and_leaves_local_fixtures_untouched(): void
    {
        $this->seedOld('000123456789', ['director-plain-en' => '%PDF-1.4 local'], '900101015555');
        $this->writeCsv([['000123456789', 1059, 'ssm/a.pdf'], ['900101015555', 1053, 'ssm/b.pdf']]);
        $this->fetcher(['sample-bucket/ssm/a.pdf' => '%PDF-1.4 a', 'sample-bucket/ssm/b.pdf' => '%PDF-1.4 b']);
        $flags = [];
        Http::fake(function ($request) use (&$flags) {
            $parts = collect($request->data());
            $zipPath = sys_get_temp_dir().'/captured-'.uniqid().'.zip';
            file_put_contents($zipPath, $parts->firstWhere('name', 'bundle')['contents']);
            $zip = new \ZipArchive;
            $zip->open($zipPath);
            $ic = explode('/', $zip->getNameIndex(0))[0];
            $zip->close();
            unlink($zipPath);
            $flags[$ic] = $parts->firstWhere('name', 'set_default')['contents'];

            return Http::response(['idValue' => $ic, 'status' => 'synced']);
        });

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789', '--push' => true])
            ->expectsOutputToContain('000123456789: pushed as default')
            ->assertExitCode(0);

        $this->assertSame(['000123456789' => '1', '900101015555' => '0'], $flags);
        Http::assertSent(fn ($request) => $request->url() === 'https://remote.example.test/admin-api/involvements');
        $this->assertSame('%PDF-1.4 local', file_get_contents($this->root.'/000123456789/director-plain-en.pdf'));
        $this->assertSame(['director-plain-en.pdf', 'meta.json'], $this->files('000123456789'));
        $this->assertSame('{"idValue":"900101015555"}', file_get_contents($this->root.'/default.json'));
        $this->assertDirectoryDoesNotExist($this->root.'/900101015555');
    }

    public function test_push_without_default_keeps_remote_default(): void
    {
        $this->writeCsv([['000123456789', 1059, 'ssm/a.pdf'], ['900101015555', 1053, 'ssm/b.pdf']]);
        $this->fetcher(['sample-bucket/ssm/a.pdf' => '%PDF-1.4 a', 'sample-bucket/ssm/b.pdf' => '%PDF-1.4 b']);
        $flags = [];
        Http::fake(function ($request) use (&$flags) {
            $flags[] = collect($request->data())->firstWhere('name', 'set_default')['contents'];

            return Http::response(['status' => 'synced']);
        });

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--push' => true])
            ->expectsOutputToContain('No --default: the remote default IC is left unchanged')
            ->assertExitCode(0);

        $this->assertSame(['0', '0'], $flags);
    }

    public function test_push_non_success_response_fails(): void
    {
        $this->writeCsv([['000123456789', 1059, 'ssm/a.pdf']]);
        $this->fetcher(['sample-bucket/ssm/a.pdf' => '%PDF-1.4 a']);
        Http::fake(['*' => Http::response(['error' => 'bundle rejected'], 422)]);

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789', '--push' => true])
            ->expectsOutputToContain('422')
            ->expectsOutputToContain('000123456789: NOT pushed')
            ->assertExitCode(1);
    }

    public function test_push_connection_failure_fails(): void
    {
        $this->writeCsv([['000123456789', 1059, 'ssm/a.pdf']]);
        $this->fetcher(['sample-bucket/ssm/a.pdf' => '%PDF-1.4 a']);
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789', '--push' => true])
            ->expectsOutputToContain('connection refused')
            ->assertExitCode(1);
    }

    public function test_push_requires_remote_configuration_before_fetching(): void
    {
        config(['ssm_mock.remote_url' => null]);
        $this->writeCsv([['000123456789', 1059, 'ssm/a.pdf']]);
        $fetcher = $this->countingFetcher();

        $this->artisan('ssm:import-involvements', ['csv' => $this->csvPath, '--default' => '000123456789', '--push' => true])
            ->expectsOutputToContain('SSM_MOCK_REMOTE_URL')
            ->assertExitCode(1);

        $this->assertSame(0, $fetcher->calls);
    }
}
