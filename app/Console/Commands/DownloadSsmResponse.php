<?php

namespace App\Console\Commands;

use App\Services\AwsS3ObjectFetcher;
use App\Services\SsmFixtureBuilder;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DownloadSsmResponse extends Command
{
    protected $signature = 'ssm:download-response
        {ids* : One or more datasource_requests ids}';

    protected $description = 'Download the SSM raw JSON and PDF report for one or more datasource_requests ids into storage/app/ssm-fixtures/cases';

    public function handle(): int
    {
        $casesDir = rtrim(config('ssm_mock.cases_path'), '/');

        $s3 = new S3Client([
            'version' => 'latest',
            'region' => env('SSM_S3_REGION'),
            'credentials' => [
                'key' => env('SSM_S3_ACCESS_KEY_ID'),
                'secret' => env('SSM_S3_SECRET_ACCESS_KEY'),
            ],
        ]);

        $builder = new SsmFixtureBuilder(new AwsS3ObjectFetcher($s3));
        $exitCode = self::SUCCESS;

        foreach ($this->argument('ids') as $id) {
            $row = $builder->findRequestRow($id);

            if ($row === null) {
                $this->error("No datasource_requests row found for id={$id}");
                $exitCode = self::FAILURE;

                continue;
            }

            $caseKey = Str::slug($row->subject_name ?: $row->subject_reg_no ?: $row->id);
            $casePath = "{$casesDir}/{$caseKey}";

            $this->info("Request {$row->id} — {$row->subject_name} ({$row->subject_reg_no}) -> cases/{$caseKey}");
            $builder->assembleCase($casePath, $row);
            $this->line("  -> {$casePath}");
        }

        return $exitCode;
    }
}
