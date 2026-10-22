<?php

namespace App\Services;

use App\Services\SsmGateway\InvolvementVariant;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Zips one IC's staged involvement bundle under {idValue}/ and posts it to the deployed mock's
 * POST /admin-api/involvements endpoint using the admin_sync credential.
 */
class InvolvementBundlePusher
{
    public function push(string $directory, string $idValue, bool $setDefault): Response
    {
        $remoteUrl = config('ssm_mock.remote_url');

        foreach ([
            'SSM_MOCK_REMOTE_URL' => $remoteUrl,
            'SSM_MOCK_ADMIN_SYNC_KEY' => config('ssm_mock.admin_sync_key'),
            'SSM_MOCK_ADMIN_SYNC_SECRET' => config('ssm_mock.admin_sync_secret'),
        ] as $env => $value) {
            if (! $value) {
                throw new \RuntimeException("{$env} is not configured");
            }
        }

        $zipPath = $this->zipBundle($directory, $idValue);

        try {
            return Http::withHeaders([
                'x-Gateway-APIKey' => config('ssm_mock.admin_sync_key'),
                'x-Gateway-APISecret' => config('ssm_mock.admin_sync_secret'),
            ])->attach('bundle', file_get_contents($zipPath), 'involvement.zip')
                ->post(rtrim($remoteUrl, '/').'/admin-api/involvements', ['set_default' => $setDefault ? '1' : '0']);
        } finally {
            @unlink($zipPath);
        }
    }

    private function zipBundle(string $directory, string $idValue): string
    {
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));

        foreach ($entries as $entry) {
            $isSample = str_ends_with($entry, '.pdf') && InvolvementVariant::isValid(substr($entry, 0, -4));

            if ($entry !== 'meta.json' && ! $isSample) {
                throw new \RuntimeException("Unexpected file {$entry} in the {$idValue} bundle");
            }
        }

        $zipPath = sys_get_temp_dir().'/'.uniqid('involvement-upload-', true).'.zip';
        $zip = new \ZipArchive;

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Could not create zip at {$zipPath}");
        }

        try {
            foreach ($entries as $entry) {
                if (! $zip->addFile($directory.'/'.$entry, $idValue.'/'.$entry)) {
                    throw new \RuntimeException("Could not add {$entry} to the {$idValue} bundle");
                }
            }

            if (! $zip->close()) {
                throw new \RuntimeException("Could not write zip at {$zipPath}");
            }
        } catch (\Throwable $e) {
            @unlink($zipPath);

            throw $e;
        }

        return $zipPath;
    }
}
