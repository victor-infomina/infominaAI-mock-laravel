<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvolvementUploadTest extends TestCase
{
    use RefreshDatabase;

    private const IC = '000123456789';

    private string $root;

    private array $zips = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/involvement-upload-test-'.uniqid();
        mkdir($this->root.'/'.self::IC, 0777, true);
        file_put_contents($this->root.'/'.self::IC.'/meta.json', json_encode(['idType' => 'MK', 'idValue' => self::IC, 'name' => 'OLD']));
        file_put_contents($this->root.'/'.self::IC.'/director-plain-en.pdf', '%PDF-1.4 old');
        file_put_contents($this->root.'/default.json', '{"idValue":"900101015555"}');
        config(['ssm_gateway_mock.involvements_path' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
        parent::tearDown();
    }

    private function headers(string $purpose = 'admin_sync'): array
    {
        [$client, $secret] = ApiClient::createWithSecret('sync-test', null, $purpose);

        return ['x-Gateway-APIKey' => $client->key, 'x-Gateway-APISecret' => $secret];
    }

    private function meta(string $idValue = self::IC, string $name = 'NEW'): string
    {
        return json_encode(['idType' => 'MK', 'idValue' => $idValue, 'name' => $name]);
    }

    /** @param array<string, string> $entries full entry name => bytes */
    private function zip(array $entries, ?callable $tweak = null): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('involvement-test-', true).'.zip';
        $this->zips[] = $path;
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $bytes) {
            str_ends_with($name, '/') ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $bytes);
        }
        if ($tweak) {
            $tweak($zip);
        }
        $zip->close();
        if (! is_file($path)) {
            // ZipArchive writes nothing for an empty archive; an end-of-central-directory record alone is a valid one.
            file_put_contents($path, "PK\x05\x06".str_repeat("\0", 18));
        }

        return $path;
    }

    private function validEntries(string $ic = self::IC): array
    {
        return [$ic.'/meta.json' => $this->meta($ic), $ic.'/secretary-ctc-en.pdf' => '%PDF-1.4 new'];
    }

    private function upload(string $zipPath, ?string $setDefault = null, ?array $headers = null)
    {
        $data = ['bundle' => new UploadedFile($zipPath, 'involvement.zip', 'application/zip', null, true)];
        if ($setDefault !== null) {
            $data['set_default'] = $setDefault;
        }

        return $this->withHeaders($headers ?? $this->headers())->post('/admin-api/involvements', $data, ['Accept' => 'application/json']);
    }

    private function snapshot(): array
    {
        $files = [];
        foreach (File::allFiles($this->root, true) as $file) {
            if ($file->getRelativePathname() !== '.import.lock') {
                $files[$file->getRelativePathname()] = $file->getContents();
            }
        }
        ksort($files);

        return $files;
    }

    public function test_publishes_bundle_and_sets_default(): void
    {
        $this->upload($this->zip($this->validEntries()), '1')
            ->assertOk()
            ->assertJson(['idValue' => self::IC, 'status' => 'synced']);

        $this->assertSame([
            self::IC.'/meta.json' => $this->meta(),
            self::IC.'/secretary-ctc-en.pdf' => '%PDF-1.4 new',
            'default.json' => '{"idValue":"'.self::IC.'"}'."\n",
        ], $this->snapshot());
    }

    public function test_publishes_without_default_by_default(): void
    {
        $this->upload($this->zip(['999/' => '', ...$this->validEntries('999')]))->assertOk()->assertJsonPath('idValue', '999');

        $this->assertSame('%PDF-1.4 new', file_get_contents($this->root.'/999/secretary-ctc-en.pdf'));
        $this->assertSame('{"idValue":"900101015555"}', file_get_contents($this->root.'/default.json'));
    }

    public function test_requires_admin_sync_credentials(): void
    {
        $before = $this->snapshot();
        $zip = $this->zip($this->validEntries());

        $this->post('/admin-api/involvements', ['bundle' => new UploadedFile($zip, 'involvement.zip', 'application/zip', null, true)], ['Accept' => 'application/json'])
            ->assertStatus(401);
        $this->upload($zip, '1', $this->headers('ssm_gateway'))->assertStatus(401);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_rejects_invalid_set_default(): void
    {
        $this->upload($this->zip($this->validEntries()), 'maybe')->assertStatus(422);
    }

    public function test_rejects_non_zip(): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('involvement-test-', true).'.zip';
        $this->zips[] = $path;
        file_put_contents($path, 'not a zip');

        $this->upload($path, '1')->assertStatus(422);
    }

    public static function unsafeBundles(): array
    {
        $ic = self::IC;
        $meta = json_encode(['idType' => 'MK', 'idValue' => $ic, 'name' => 'NEW']);
        $pdf = '%PDF-1.4 new';

        return [
            'empty' => [[]],
            'two roots' => [["{$ic}/meta.json" => $meta, "{$ic}/director-ctc-en.pdf" => $pdf, '999/meta.json' => $meta]],
            'traversal' => [["{$ic}/meta.json" => $meta, "{$ic}/../director-ctc-en.pdf" => $pdf]],
            'absolute' => [["/{$ic}/meta.json" => $meta, "/{$ic}/director-ctc-en.pdf" => $pdf]],
            'backslash' => [["{$ic}/meta.json" => $meta, "{$ic}\\director-ctc-en.pdf" => $pdf]],
            'dot segment' => [["{$ic}/meta.json" => $meta, "{$ic}/./director-ctc-en.pdf" => $pdf]],
            'nested file' => [["{$ic}/meta.json" => $meta, "{$ic}/sub/director-ctc-en.pdf" => $pdf]],
            'top-level file' => [["{$ic}/meta.json" => $meta, "{$ic}/director-ctc-en.pdf" => $pdf, 'meta.json' => $meta]],
            'unsafe root' => [['bad ic/meta.json' => $meta, 'bad ic/director-ctc-en.pdf' => $pdf]],
            'unknown identity' => [["{$ic}/meta.json" => $meta, "{$ic}/partner-ctc-en.pdf" => $pdf]],
            'legacy report' => [["{$ic}/meta.json" => $meta, "{$ic}/report.pdf" => $pdf]],
            'metadata mismatch' => [["{$ic}/meta.json" => json_encode(['idType' => 'MK', 'idValue' => '123456789', 'name' => 'NEW']), "{$ic}/director-ctc-en.pdf" => $pdf]],
            'missing meta' => [["{$ic}/director-ctc-en.pdf" => $pdf]],
            'missing pdfs' => [["{$ic}/meta.json" => $meta]],
            'not a pdf' => [["{$ic}/meta.json" => $meta, "{$ic}/director-ctc-en.pdf" => '<html>']],
        ];
    }

    #[DataProvider('unsafeBundles')]
    public function test_rejects_unsafe_bundle_without_changes(array $entries): void
    {
        $before = $this->snapshot();

        $this->upload($this->zip($entries), '1')->assertStatus(422);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_rejects_symlink_entries(): void
    {
        $before = $this->snapshot();
        $zip = $this->zip($this->validEntries(), function (\ZipArchive $zip) {
            $zip->addFromString(self::IC.'/director-ctc-en.pdf', '%PDF-1.4 link');
            $zip->setExternalAttributesName(self::IC.'/director-ctc-en.pdf', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });

        $this->upload($zip, '1')->assertStatus(422);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_rejects_duplicate_entries(): void
    {
        $before = $this->snapshot();
        $zip = $this->zip([
            self::IC.'/meta.json' => $this->meta(),
            self::IC.'/director-ctc-en.pdf' => '%PDF-1.4 first',
            self::IC.'/director-ctc-eX.pdf' => '%PDF-1.4 second',
        ]);
        // ZipArchive refuses to add a duplicate name, so rename the third entry in the raw bytes.
        file_put_contents($zip, str_replace('director-ctc-eX.pdf', 'director-ctc-en.pdf', file_get_contents($zip)));

        $this->upload($zip, '1')->assertStatus(422);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_installer_failure_returns_500_and_preserves_state(): void
    {
        unlink($this->root.'/default.json');
        mkdir($this->root.'/default.json');
        $before = $this->snapshot();

        $this->upload($this->zip($this->validEntries()), '1')->assertStatus(500);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('%PDF-1.4 old', file_get_contents($this->root.'/'.self::IC.'/director-plain-en.pdf'));
    }
}
