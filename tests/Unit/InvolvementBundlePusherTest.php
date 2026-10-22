<?php

namespace Tests\Unit;

use App\Services\InvolvementBundlePusher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvolvementBundlePusherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ssm_mock.remote_url' => 'https://remote.example.test/',
            'ssm_mock.admin_sync_key' => 'remote-key',
            'ssm_mock.admin_sync_secret' => 'remote-secret',
        ]);
        $this->dir = sys_get_temp_dir().'/involvement-pusher-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/meta.json', '{"idType":"MK","idValue":"000123456789","name":"X"}');
        file_put_contents($this->dir.'/director-plain-en.pdf', '%PDF-1.4 en');
        file_put_contents($this->dir.'/secretary-ctc-my.pdf', '%PDF-1.4 my');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function tempZips(): array
    {
        return glob(sys_get_temp_dir().'/involvement-upload-*.zip') ?: [];
    }

    /** @return array{0: array<string, string>, 1: ?string} [zip entries, set_default] */
    private function pushCapturing(bool $setDefault): array
    {
        $entries = [];
        $flag = null;
        Http::fake(function ($request) use (&$entries, &$flag) {
            $parts = collect($request->data());
            $flag = $parts->firstWhere('name', 'set_default')['contents'] ?? null;
            $zipPath = sys_get_temp_dir().'/captured-'.uniqid().'.zip';
            file_put_contents($zipPath, $parts->firstWhere('name', 'bundle')['contents']);
            $zip = new \ZipArchive;
            $zip->open($zipPath);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
            }
            $zip->close();
            unlink($zipPath);

            return Http::response(['idValue' => '000123456789', 'status' => 'synced'], 200);
        });

        $response = (new InvolvementBundlePusher)->push($this->dir, '000123456789', $setDefault);
        $this->assertTrue($response->successful());
        ksort($entries);

        return [$entries, $flag];
    }

    public function test_posts_zip_under_ic_root_with_admin_sync_headers_and_flag(): void
    {
        $before = $this->tempZips();

        [$entries, $flag] = $this->pushCapturing(true);

        $this->assertSame('1', $flag);
        $this->assertSame([
            '000123456789/director-plain-en.pdf' => '%PDF-1.4 en',
            '000123456789/meta.json' => '{"idType":"MK","idValue":"000123456789","name":"X"}',
            '000123456789/secretary-ctc-my.pdf' => '%PDF-1.4 my',
        ], $entries);
        Http::assertSent(fn ($request) => $request->url() === 'https://remote.example.test/admin-api/involvements'
            && $request->hasHeader('x-Gateway-APIKey', 'remote-key')
            && $request->hasHeader('x-Gateway-APISecret', 'remote-secret'));
        $this->assertSame($before, $this->tempZips());
    }

    public function test_sends_zero_flag_when_not_default(): void
    {
        [, $flag] = $this->pushCapturing(false);

        $this->assertSame('0', $flag);
    }

    public function test_rejects_unexpected_bundle_files(): void
    {
        file_put_contents($this->dir.'/notes.txt', 'x');
        Http::fake();

        try {
            (new InvolvementBundlePusher)->push($this->dir, '000123456789', false);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('notes.txt', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_cleans_temporary_zip_on_connection_failure(): void
    {
        $before = $this->tempZips();
        Http::fake(fn () => throw new ConnectionException('refused'));

        try {
            (new InvolvementBundlePusher)->push($this->dir, '000123456789', false);
            $this->fail('Expected failure');
        } catch (ConnectionException) {
        }

        $this->assertSame($before, $this->tempZips());
    }

    public function test_returns_non_successful_response_and_cleans_up(): void
    {
        $before = $this->tempZips();
        Http::fake(['*' => Http::response(['error' => 'bad'], 422)]);

        $response = (new InvolvementBundlePusher)->push($this->dir, '000123456789', false);

        $this->assertSame(422, $response->status());
        $this->assertSame($before, $this->tempZips());
    }

    public function test_requires_remote_configuration_before_sending(): void
    {
        foreach (['ssm_mock.remote_url' => 'SSM_MOCK_REMOTE_URL', 'ssm_mock.admin_sync_key' => 'SSM_MOCK_ADMIN_SYNC_KEY', 'ssm_mock.admin_sync_secret' => 'SSM_MOCK_ADMIN_SYNC_SECRET'] as $key => $env) {
            $saved = config($key);
            config([$key => null]);
            Http::fake();

            try {
                (new InvolvementBundlePusher)->push($this->dir, '000123456789', false);
                $this->fail('Expected failure');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($env, $e->getMessage());
            }

            Http::assertNothingSent();
            config([$key => $saved]);
        }
    }
}
