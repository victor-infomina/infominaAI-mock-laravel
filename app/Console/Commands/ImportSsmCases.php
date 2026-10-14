<?php

namespace App\Console\Commands;

use App\Services\CaseBundlePusher;
use App\Services\SsmCaseCsvReader;
use App\Services\SsmFixtureBuilder;
use App\Services\SsmIdamanCsvReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportSsmCases extends Command
{
    protected $signature = 'ssm:import-cases
        {csv : Path to a CSV with columns company_name, reg_no, entity_type, raw_s3_url, pdf_s3_url}
        {--idaman= : Optional path to a CSV of Idaman documents with columns reg_no, version_id, form, document_date, s3_url}
        {--push : Push each case to SSM_MOCK_REMOTE_URL instead of writing it into the local cases folder}';

    protected $description = 'Build SSM mock cases from a CSV of purchased reports (raw JSON + PDF pulled from S3, optionally Idaman documents from a second CSV), writing them locally or pushing to the deployed mock';

    public function handle(SsmFixtureBuilder $builder, CaseBundlePusher $pusher): int
    {
        $reader = new SsmCaseCsvReader(config('ssm_mock.s3_bucket'), config('ssm_mock.s3_region'));

        try {
            $rows = $reader->read($this->argument('csv'));
            $idamanByRegNo = $this->option('idaman')
                ? (new SsmIdamanCsvReader)->read($this->option('idaman'))
                : [];
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->warnAboutOrphanIdamanRows($idamanByRegNo, $rows);

        $push = (bool) $this->option('push');
        $casesDir = rtrim(config('ssm_mock.cases_path'), '/');
        $failures = 0;

        $idamanCount = array_sum(array_map('count', $idamanByRegNo));
        $this->info(
            count($rows).' row(s)'.($idamanCount > 0 ? " with {$idamanCount} idaman document(s)" : '')
            .' to import'.($push ? ' and push to '.config('ssm_mock.remote_url') : " into {$casesDir}"),
        );

        foreach ($rows as $row) {
            $caseKey = Str::slug($row->subject_name ?: $row->subject_reg_no ?: $row->id);
            $label = "{$row->subject_name} ({$row->subject_reg_no}, {$row->type}) -> {$caseKey}";
            $tempRoot = sys_get_temp_dir().'/ssm-import-'.uniqid();
            $tempCasePath = "{$tempRoot}/{$caseKey}";

            try {
                $builder->assembleCase($tempCasePath, $row);
                $builder->assembleIdamanDocuments(
                    $tempCasePath,
                    $idamanByRegNo[$row->subject_reg_no] ?? [],
                    config('ssm_mock.s3_bucket'),
                );

                if ($push) {
                    $response = $pusher->push($tempCasePath, $caseKey);

                    if (! $response->successful()) {
                        throw new \RuntimeException('failed: '.$response->status().' '.$response->body());
                    }

                    $this->info("{$label}: synced: ".$response->json('caseKey'));
                } else {
                    $this->moveIntoCases($tempCasePath, "{$casesDir}/{$caseKey}");
                    $this->info("{$label}: written");
                }
            } catch (\Throwable $e) {
                $failures++;
                $this->error("{$label}: ".$e->getMessage());
            } finally {
                File::deleteDirectory($tempRoot);
            }
        }

        if ($failures > 0) {
            $this->error("{$failures} of ".count($rows).' row(s) failed');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, list<object>>  $idamanByRegNo
     * @param  list<object>  $rows
     */
    private function warnAboutOrphanIdamanRows(array $idamanByRegNo, array $rows): void
    {
        $knownRegNos = array_flip(array_map(fn ($row) => $row->subject_reg_no, $rows));

        foreach ($idamanByRegNo as $regNo => $documents) {
            if (! isset($knownRegNos[$regNo])) {
                $this->warn(count($documents)." idaman document(s) for {$regNo} skipped: no matching reg_no in the cases CSV");
            }
        }
    }

    private function moveIntoCases(string $from, string $to): void
    {
        File::ensureDirectoryExists(dirname($to));

        if (is_dir($to)) {
            File::deleteDirectory($to);
        }

        if (! File::copyDirectory($from, $to)) {
            throw new \RuntimeException("Could not write case folder {$to}");
        }
    }
}
