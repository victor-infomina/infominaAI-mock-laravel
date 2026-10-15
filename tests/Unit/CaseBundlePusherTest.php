<?php

namespace Tests\Unit;

use App\Services\CaseBundlePusher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CaseBundlePusherTest extends TestCase
{
    public function test_zips_case_directory_under_case_key_and_posts_it_with_admin_sync_headers(): void
    {
        config([
            'ssm_mock.remote_url' => 'https://remote.example.test/',
            'ssm_mock.admin_sync_key' => 'remote-key',
            'ssm_mock.admin_sync_secret' => 'remote-secret',
        ]);

        $caseDir = sys_get_temp_dir().'/case-bundle-pusher-'.uniqid();
        mkdir($caseDir.'/idaman', 0777, true);
        file_put_contents($caseDir.'/meta.json', '{"regNo":"1"}');
        file_put_contents($caseDir.'/idaman/V1.tiff', 'tiff-bytes');

        $capturedZipPath = null;
        Http::fake(function ($request) use (&$capturedZipPath) {
            $bundle = collect($request->data())->firstWhere('name', 'bundle');
            $capturedZipPath = sys_get_temp_dir().'/captured-'.uniqid().'.zip';
            file_put_contents($capturedZipPath, $bundle['contents']);

            return Http::response(['caseKey' => 'acme-sdn-bhd', 'status' => 'synced'], 200);
        });

        $response = (new CaseBundlePusher())->push($caseDir, 'acme-sdn-bhd');

        $this->assertTrue($response->successful());
        $this->assertSame('acme-sdn-bhd', $response->json('caseKey'));

        Http::assertSent(fn ($request) => $request->url() === 'https://remote.example.test/admin-api/cases'
            && $request->hasHeader('x-Gateway-APIKey', 'remote-key')
            && $request->hasHeader('x-Gateway-APISecret', 'remote-secret'));

        $zip = new \ZipArchive();
        $zip->open($capturedZipPath);
        $this->assertSame('{"regNo":"1"}', $zip->getFromName('acme-sdn-bhd/meta.json'));
        $this->assertSame('tiff-bytes', $zip->getFromName('acme-sdn-bhd/idaman/V1.tiff'));
        $zip->close();
    }

    public function test_throws_when_remote_is_not_configured(): void
    {
        config(['ssm_mock.remote_url' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SSM_MOCK_REMOTE_URL');

        (new CaseBundlePusher())->push(sys_get_temp_dir(), 'x');
    }
}
