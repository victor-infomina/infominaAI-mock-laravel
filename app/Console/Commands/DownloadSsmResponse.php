<?php

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DownloadSsmResponse extends Command
{
    /**
     * Looks up datasource_requests on the infominaAI-BE database and writes
     * the raw SSM JSON + PDF report from S3 into storage/app/ssm-fixtures/cases,
     * in the same layout SsmCaseRepository/SsmMockController read from.
     */
    protected $signature = 'ssm:download-response
        {ids* : One or more datasource_requests ids}';

    protected $description = 'Download the SSM raw JSON and PDF report for one or more datasource_requests ids into storage/app/ssm-fixtures/cases';

    public function handle(): int
    {
        $ids = $this->argument('ids');
        $casesDir = rtrim(config('ssm_mock.cases_path'), '/');

        $s3 = new S3Client([
            'version' => 'latest',
            'region' => env('SSM_S3_REGION'),
            'credentials' => [
                'key' => env('SSM_S3_ACCESS_KEY_ID'),
                'secret' => env('SSM_S3_SECRET_ACCESS_KEY'),
            ],
        ]);

        $exitCode = self::SUCCESS;

        foreach ($ids as $id) {
            $rows = DB::connection('be_pgsql')
                ->table('datasource_requests as dr')
                ->leftJoin('datasource_transactions as dt', 'dt.request_id_id', '=', 'dr.id')
                ->leftJoin('datasource_purchased as dp', 'dp.transaction_id_id', '=', 'dt.id')
                ->leftJoin('datasource_reports as drep', 'drep.id', '=', 'dp.report_id')
                ->where('dr.id', $id)
                ->select([
                    'dr.id',
                    'dr.subject_name',
                    'dr.subject_reg_no',
                    'dr.type',
                    'dp.raw_s3_url',
                    'drep.s3_url as pdf_s3_url',
                ])
                ->get();

            if ($rows->isEmpty()) {
                $this->error("No datasource_requests row found for id={$id}");
                $exitCode = self::FAILURE;

                continue;
            }

            foreach ($rows as $row) {
                $entityType = strtolower($row->type ?: 'company');
                $caseKey = Str::slug($row->subject_name ?: $row->subject_reg_no ?: $row->id);
                $casePath = "{$casesDir}/{$caseKey}";

                $this->info("Request {$row->id} — {$row->subject_name} ({$row->subject_reg_no}) -> cases/{$caseKey}");

                if (! is_dir($casePath) && ! mkdir($casePath, 0755, true) && ! is_dir($casePath)) {
                    $this->error("  Could not create {$casePath}");
                    $exitCode = self::FAILURE;

                    continue;
                }

                $this->downloadIfPresent($s3, $row->raw_s3_url, "{$casePath}/{$entityType}-profile.json");
                $this->downloadIfPresent($s3, $row->pdf_s3_url, "{$casePath}/report.pdf");

                file_put_contents("{$casePath}/meta.json", json_encode([
                    'regNo' => $row->subject_reg_no,
                    'companyName' => $row->subject_name,
                    'entityType' => $entityType,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
                $this->line("  -> {$casePath}/meta.json");
            }
        }

        return $exitCode;
    }

    private function downloadIfPresent(S3Client $s3, ?string $url, string $destPath): void
    {
        $label = basename($destPath);

        if (! $url) {
            $this->line("  (missing) {$label}");

            return;
        }

        // Virtual-hosted style: https://{bucket}.s3.{region}.amazonaws.com/{key}
        if (! preg_match('#^https://(.+?)\.s3\.(.+?)\.amazonaws\.com/(.+)$#', $url, $matches)) {
            $this->warn("  Unrecognized S3 URL, skipping: {$url}");

            return;
        }

        [, $bucket, , $key] = $matches;
        $key = rawurldecode($key);

        $result = $s3->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);

        file_put_contents($destPath, $result['Body']);
        $this->line("  -> {$destPath}");
    }
}
