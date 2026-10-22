<?php

namespace Tests\Unit;

use App\Services\SsmInvolvementBundleInstaller;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SsmInvolvementBundleInstallerTest extends TestCase
{
    private string $tmp;

    private string $root;

    private SsmInvolvementBundleInstaller $installer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/involvement-installer-'.uniqid();
        $this->root = $this->tmp.'/root';
        mkdir($this->root, 0777, true);
        $this->installer = new SsmInvolvementBundleInstaller;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    /** @param array<string, string> $pdfs identity => bytes */
    private function bundle(string $idValue, array $pdfs, ?array $meta = null): string
    {
        $dir = $this->tmp.'/src-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/meta.json', json_encode($meta ?? ['idType' => 'MK', 'idValue' => $idValue, 'name' => 'SAMPLE']));
        foreach ($pdfs as $identity => $bytes) {
            file_put_contents($dir.'/'.$identity.'.pdf', $bytes);
        }

        return $dir;
    }

    private function seedOld(): void
    {
        $this->installer->install(
            $this->bundle('000123456789', ['director-plain-en' => '%PDF-1.4 old en', 'director-plain-my' => '%PDF-1.4 old my']),
            $this->root, '000123456789', true,
        );
    }

    private function snapshot(): array
    {
        $files = [];
        foreach (File::allFiles($this->root, true) as $file) {
            if ($file->getRelativePathname() === '.import.lock') {
                continue;
            }
            $files[$file->getRelativePathname()] = $file->getContents();
        }
        ksort($files);

        return $files;
    }

    private function assertInvalid(string $directory, string $message): void
    {
        try {
            $this->installer->validate($directory, '000123456789');
            $this->fail('Expected validation failure');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_installs_bundle_and_sets_default(): void
    {
        $this->seedOld();

        $this->assertSame('%PDF-1.4 old en', file_get_contents($this->root.'/000123456789/director-plain-en.pdf'));
        $this->assertSame('000123456789', json_decode(file_get_contents($this->root.'/000123456789/meta.json'), true)['idValue']);
        $this->assertSame(['idValue' => '000123456789'], json_decode(file_get_contents($this->root.'/default.json'), true));
        $this->assertSame(['000123456789/director-plain-en.pdf', '000123456789/director-plain-my.pdf', '000123456789/meta.json', 'default.json'], array_keys($this->snapshot()));
    }

    public function test_creates_missing_root(): void
    {
        $root = $this->tmp.'/new/root';

        $this->installer->install($this->bundle('000123456789', ['director-plain-en' => '%PDF-1.4 x']), $root, '000123456789', false);

        $this->assertFileExists($root.'/000123456789/director-plain-en.pdf');
        $this->assertFileDoesNotExist($root.'/default.json');
    }

    public function test_replacement_removes_absent_variants_and_keeps_default_unless_asked(): void
    {
        $this->seedOld();
        $this->installer->install($this->bundle('900101015555', ['secretary-ctc-en' => '%PDF-1.4 other']), $this->root, '900101015555', false);

        $this->installer->install($this->bundle('000123456789', ['shareholder-ctc-my' => '%PDF-1.4 new']), $this->root, '000123456789', false);

        $this->assertSame([
            '000123456789/meta.json', '000123456789/shareholder-ctc-my.pdf',
            '900101015555/meta.json', '900101015555/secretary-ctc-en.pdf', 'default.json',
        ], array_keys($this->snapshot()));
        $this->assertSame(['idValue' => '000123456789'], json_decode(file_get_contents($this->root.'/default.json'), true));
    }

    public function test_set_default_moves_marker(): void
    {
        $this->seedOld();

        $this->installer->install($this->bundle('900101015555', ['secretary-ctc-en' => '%PDF-1.4 other']), $this->root, '900101015555', true);

        $this->assertSame(['idValue' => '900101015555'], json_decode(file_get_contents($this->root.'/default.json'), true));
    }

    public function test_invalid_replacement_preserves_old_bundle_and_default(): void
    {
        $this->seedOld();
        $before = $this->snapshot();

        try {
            $this->installer->install($this->bundle('000123456789', ['director-ctc-en' => 'not a pdf']), $this->root, '000123456789', true);
            $this->fail('Expected failure');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(['000123456789', 'default.json'], array_values(array_diff(scandir($this->root), ['.', '..', '.import.lock'])));
    }

    public function test_marker_write_failure_rolls_back_bundle(): void
    {
        $this->installer->install($this->bundle('000123456789', ['director-plain-en' => '%PDF-1.4 old']), $this->root, '000123456789', false);
        mkdir($this->root.'/default.json');
        $before = $this->snapshot();

        try {
            $this->installer->install($this->bundle('000123456789', ['director-ctc-en' => '%PDF-1.4 new']), $this->root, '000123456789', true);
            $this->fail('Expected failure');
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertDirectoryExists($this->root.'/default.json');
        $this->assertSame(['000123456789', 'default.json'], array_values(array_diff(scandir($this->root), ['.', '..', '.import.lock'])));
    }

    public function test_symlinked_marker_is_an_error_and_rolls_back(): void
    {
        $this->seedOld();
        file_put_contents($this->tmp.'/elsewhere.json', '{"idValue":"000123456789"}');
        unlink($this->root.'/default.json');
        symlink($this->tmp.'/elsewhere.json', $this->root.'/default.json');

        try {
            $this->installer->install($this->bundle('000123456789', ['director-ctc-en' => '%PDF-1.4 new']), $this->root, '000123456789', true);
            $this->fail('Expected failure');
        } catch (\RuntimeException) {
        }

        $this->assertTrue(is_link($this->root.'/default.json'));
        $this->assertSame('%PDF-1.4 old en', file_get_contents($this->root.'/000123456789/director-plain-en.pdf'));
        $this->assertFileDoesNotExist($this->root.'/000123456789/director-ctc-en.pdf');
    }

    public function test_validate_rejects_missing_metadata(): void
    {
        $dir = $this->bundle('000123456789', ['director-plain-en' => '%PDF-1.4 x']);
        unlink($dir.'/meta.json');

        $this->assertInvalid($dir, 'meta.json');
    }

    public function test_validate_rejects_bad_metadata(): void
    {
        $this->assertInvalid($this->bundle('x', ['director-plain-en' => '%PDF-1.4 x'], ['idType' => 'MK', 'idValue' => '123456789', 'name' => 'X']), 'does not match');
        $this->assertInvalid($this->bundle('x', ['director-plain-en' => '%PDF-1.4 x'], ['idType' => 'MK', 'idValue' => '000123456789', 'name' => '']), 'name');
        $this->assertInvalid($this->bundle('x', ['director-plain-en' => '%PDF-1.4 x'], ['idType' => 'MK', 'idValue' => 123456789, 'name' => 'X']), 'idValue');

        $dir = $this->bundle('000123456789', ['director-plain-en' => '%PDF-1.4 x']);
        file_put_contents($dir.'/meta.json', '{broken');
        $this->assertInvalid($dir, 'meta.json');
    }

    public function test_validate_rejects_unsafe_ic(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->installer->validate($this->bundle('../x', ['director-plain-en' => '%PDF-1.4 x']), '../x');
    }

    public function test_validate_rejects_unrecognised_files(): void
    {
        $this->assertInvalid($this->bundle('000123456789', ['report' => '%PDF-1.4 x']), 'report.pdf');
        $this->assertInvalid($this->bundle('000123456789', ['partner-ctc-en' => '%PDF-1.4 x']), 'partner-ctc-en.pdf');

        $dir = $this->bundle('000123456789', ['director-plain-en' => '%PDF-1.4 x']);
        file_put_contents($dir.'/notes.txt', 'x');
        $this->assertInvalid($dir, 'notes.txt');
    }

    public function test_validate_rejects_directories_and_symlinks(): void
    {
        $dir = $this->bundle('000123456789', ['director-plain-en' => '%PDF-1.4 x']);
        mkdir($dir.'/director-ctc-en.pdf');
        $this->assertInvalid($dir, 'director-ctc-en.pdf');

        $dir = $this->bundle('000123456789', ['director-plain-en' => '%PDF-1.4 x']);
        symlink($dir.'/director-plain-en.pdf', $dir.'/director-ctc-en.pdf');
        $this->assertInvalid($dir, 'director-ctc-en.pdf');

        $dir = $this->bundle('000123456789', ['director-plain-en' => '%PDF-1.4 x']);
        $link = $this->tmp.'/link-'.uniqid();
        symlink($dir, $link);
        $this->assertInvalid($link, 'directory');
    }

    public function test_validate_rejects_empty_or_non_pdf_files_and_zero_pdfs(): void
    {
        $this->assertInvalid($this->bundle('000123456789', ['director-plain-en' => '']), 'director-plain-en.pdf');
        $this->assertInvalid($this->bundle('000123456789', ['director-plain-en' => '<html>']), 'director-plain-en.pdf');
        $this->assertInvalid($this->bundle('000123456789', []), 'no PDF');
    }
}
