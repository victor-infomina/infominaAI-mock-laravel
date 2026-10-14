<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Zips an assembled case folder under {caseKey}/ and posts it to the deployed
 * mock's POST /admin-api/cases endpoint using the admin_sync credential.
 */
class CaseBundlePusher
{
    public function push(string $caseDirectory, string $caseKey): Response
    {
        $remoteUrl = config('ssm_mock.remote_url');

        if (! $remoteUrl) {
            throw new \RuntimeException('SSM_MOCK_REMOTE_URL is not configured');
        }

        $zipPath = $this->zipDirectory($caseDirectory, $caseKey);

        try {
            return Http::withHeaders([
                'x-Gateway-APIKey' => config('ssm_mock.admin_sync_key'),
                'x-Gateway-APISecret' => config('ssm_mock.admin_sync_secret'),
            ])->attach('bundle', file_get_contents($zipPath), 'case.zip')
                ->post(rtrim($remoteUrl, '/').'/admin-api/cases');
        } finally {
            @unlink($zipPath);
        }
    }

    private function zipDirectory(string $directory, string $topDirName): string
    {
        $zipPath = sys_get_temp_dir().'/'.uniqid('sync-upload-', true).'.zip';
        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Could not create zip at {$zipPath}");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            $relative = $topDirName.'/'.substr($file->getPathname(), strlen($directory) + 1);
            $zip->addFile($file->getPathname(), $relative);
        }

        $zip->close();

        return $zipPath;
    }
}
