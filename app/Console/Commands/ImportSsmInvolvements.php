<?php

namespace App\Console\Commands;

use App\Services\InvolvementBundlePusher;
use App\Services\S3ObjectFetcher;
use App\Services\SsmGateway\InvolvementVariant;
use App\Services\SsmInvolvementBundleInstaller;
use App\Services\SsmInvolvementCsvReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportSsmInvolvements extends Command
{
    protected $signature = 'ssm:import-involvements
        {csv : CSV with identification_type, identification_no, subject_name, variant_id, pdf_s3_key}
        {--default= : IC to publish as the default; required unless --push, where omitting it leaves the remote default unchanged}
        {--push : Publish remotely instead of changing local fixtures}';

    protected $description = 'Build SSM Gateway Director / Shareholder / Secretary involvement samples from a production CSV (PDFs pulled from S3), one bundle per IC, writing them locally or pushing to the deployed mock';

    public function handle(
        SsmInvolvementCsvReader $reader,
        S3ObjectFetcher $fetcher,
        SsmInvolvementBundleInstaller $installer,
        InvolvementBundlePusher $pusher,
    ): int {
        $defaultId = $this->option('default') === null ? '' : (string) $this->option('default');
        $push = (bool) $this->option('push');
        $bucket = config('ssm_mock.s3_bucket');
        $root = rtrim((string) config('ssm_gateway_mock.involvements_path'), '/');

        $settings = ['SSM_S3_BUCKET' => $bucket, 'SSM_S3_REGION' => config('ssm_mock.s3_region')];

        if ($push) {
            $settings += [
                'SSM_MOCK_REMOTE_URL' => config('ssm_mock.remote_url'),
                'SSM_MOCK_ADMIN_SYNC_KEY' => config('ssm_mock.admin_sync_key'),
                'SSM_MOCK_ADMIN_SYNC_SECRET' => config('ssm_mock.admin_sync_secret'),
            ];
        }

        if ($defaultId === '' && ! $push) {
            $this->error('--default is required: name the IC whose samples serve unknown ICs');

            return self::FAILURE;
        }

        if ($defaultId === '') {
            $this->warn('No --default: the remote default IC is left unchanged');
        }

        $missingSettings = array_keys(array_filter($settings, fn ($value) => ! $value));

        if ($missingSettings !== []) {
            $this->error(implode(', ', $missingSettings).' must be configured');

            return self::FAILURE;
        }

        try {
            $result = $reader->read($this->argument('csv'), $defaultId === '' ? null : $defaultId);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        $this->info(count($result['groups']).' IC(s) to import'.($push ? ' and push to '.config('ssm_mock.remote_url') : " into {$root}"));
        $failed = false;

        foreach ($result['groups'] as $group) {
            $isDefault = $group['idValue'] === $defaultId;
            $published = $this->importGroup($group, $isDefault, $push, $bucket, $root, $fetcher, $installer, $pusher);
            $failed = $failed || ! $published;
            $this->reportCoverage($group, $published, $isDefault, $push);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function importGroup(
        array $group, bool $isDefault, bool $push, string $bucket, string $root,
        S3ObjectFetcher $fetcher, SsmInvolvementBundleInstaller $installer, InvolvementBundlePusher $pusher,
    ): bool {
        $idValue = $group['idValue'];
        $tempRoot = sys_get_temp_dir().'/'.uniqid('involvement-import-', true);
        $directory = "{$tempRoot}/{$idValue}";

        try {
            $meta = json_encode($group['meta'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

            if (! mkdir($directory, 0755, true) || file_put_contents("{$directory}/meta.json", $meta) !== strlen($meta)) {
                throw new \RuntimeException('Could not stage meta.json');
            }
        } catch (\Throwable $e) {
            $this->error("{$idValue}: ".$e->getMessage());
            File::deleteDirectory($tempRoot);

            return false;
        }

        try {
            $rowsFailed = false;

            foreach ($group['rows'] as $row) {
                try {
                    $bytes = $fetcher->fetch($bucket, $row['pdf_s3_key']);

                    if (! str_starts_with($bytes, '%PDF-')) {
                        throw new \RuntimeException('S3 object is not a PDF');
                    }

                    if (file_put_contents("{$directory}/{$row['identity']}.pdf", $bytes) !== strlen($bytes)) {
                        throw new \RuntimeException('Could not stage PDF');
                    }
                } catch (\Throwable $e) {
                    $rowsFailed = true;
                    $this->error("CSV row {$row['line']}: {$idValue} {$row['identity']} ({$row['pdf_s3_key']}): ".$e->getMessage());
                }
            }

            if ($rowsFailed) {
                return false;
            }

            try {
                $installer->validate($directory, $idValue);

                if ($push) {
                    $response = $pusher->push($directory, $idValue, $isDefault);

                    if (! $response->successful()) {
                        throw new \RuntimeException('remote responded '.$response->status().' '.$response->body());
                    }
                } else {
                    $installer->install($directory, $root, $idValue, $isDefault);
                }
            } catch (\Throwable $e) {
                $this->error("{$idValue}: ".$e->getMessage());

                return false;
            }

            return true;
        } finally {
            File::deleteDirectory($tempRoot);
        }
    }

    private function reportCoverage(array $group, bool $published, bool $isDefault, bool $push): void
    {
        $supplied = array_column($group['rows'], 'identity');
        $missing = array_values(array_diff(InvolvementVariant::identities(), $supplied));
        sort($supplied);
        sort($missing);
        $coverage = 'imported: '.implode(', ', $supplied).'; missing: '.($missing === [] ? 'none' : implode(', ', $missing));
        $verb = $push ? 'pushed' : 'published';

        if ($published) {
            $this->info("{$group['idValue']}: {$verb}".($isDefault ? ' as default' : '')." ({$coverage})");
        } else {
            $this->error("{$group['idValue']}: NOT {$verb}; the previous bundle".($isDefault ? ' and default' : '')." are kept (supplied {$coverage})");
        }
    }
}
