<?php

namespace Tests\Unit;

use App\Services\SsmInvolvementCsvReader;
use PHPUnit\Framework\TestCase;

class SsmInvolvementCsvReaderTest extends TestCase
{
    private const HEADER = ['identification_type', 'identification_no', 'subject_name', 'variant_id', 'pdf_s3_key'];

    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function csv(array $rows, ?array $header = self::HEADER, string $prefix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'involvement-csv-');
        $this->paths[] = $path;
        $handle = fopen($path, 'w');
        fwrite($handle, $prefix);
        if ($header !== null) {
            fputcsv($handle, $header, ',', '"', '');
        }
        foreach ($rows as $row) {
            if ($row === []) {
                fwrite($handle, "\n");
            } else {
                fputcsv($handle, $row, ',', '"', '');
            }
        }
        fclose($handle);

        return $path;
    }

    private function assertRejected(string $path, string $default, string $message): void
    {
        try {
            (new SsmInvolvementCsvReader)->read($path, $default);
            $this->fail('Expected preflight rejection');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_preserves_leading_zeroes_and_uses_production_ids(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'involvement-csv-');
        try {
            file_put_contents($path, "identification_type,identification_no,subject_name,variant_id,pdf_s3_key\nMK,000123456789,Sample,1059,ssm/sample.pdf\n");
            $result = (new SsmInvolvementCsvReader)->read($path, '000123456789');
            $this->assertSame('000123456789', $result['groups'][0]['idValue']);
            $this->assertSame('director-plain-en', $result['groups'][0]['rows'][0]['identity']);
            $this->assertSame('ssm/sample.pdf', $result['groups'][0]['rows'][0]['pdf_s3_key']);
        } finally {
            unlink($path);
        }
    }

    public function test_groups_rows_by_ic_with_metadata_and_line_numbers(): void
    {
        $path = $this->csv([
            ['MK', '000123456789', 'Sample One', '1059', 'ssm/a.pdf'],
            ['MK', '900101015555', 'Sample Two', '1060', 'ssm/b.pdf'],
            [],
            ['MK', '000123456789', 'Sample One', '1053', 'ssm/c.pdf'],
        ]);

        $result = (new SsmInvolvementCsvReader)->read($path, '000123456789');

        $this->assertSame([], $result['warnings']);
        $this->assertCount(2, $result['groups']);
        [$first, $second] = $result['groups'];
        $this->assertSame(['idType' => 'MK', 'idValue' => '000123456789', 'name' => 'Sample One'], $first['meta']);
        $this->assertSame([
            ['line' => 2, 'identity' => 'director-plain-en', 'pdf_s3_key' => 'ssm/a.pdf'],
            ['line' => 5, 'identity' => 'secretary-ctc-en', 'pdf_s3_key' => 'ssm/c.pdf'],
        ], $first['rows']);
        $this->assertSame('900101015555', $second['idValue']);
        $this->assertSame('director-ctc-en', $second['rows'][0]['identity']);
    }

    public function test_accepts_reordered_bom_header(): void
    {
        $path = $this->csv(
            [['ssm/a.pdf', '1059', 'Sample', '000123456789', 'MK']],
            ['PDF_S3_KEY', 'variant_id', ' subject_name ', 'identification_no', 'identification_type'],
            "\xEF\xBB\xBF",
        );

        $result = (new SsmInvolvementCsvReader)->read($path, '000123456789');

        $this->assertSame('Sample', $result['groups'][0]['meta']['name']);
        $this->assertSame('ssm/a.pdf', $result['groups'][0]['rows'][0]['pdf_s3_key']);
    }

    public function test_rejects_missing_header_column(): void
    {
        $path = $this->csv([['MK', '000123456789', 'Sample', '1059']], array_slice(self::HEADER, 0, 4));

        $this->assertRejected($path, '000123456789', 'missing required column(s): pdf_s3_key');
    }

    public function test_rejects_duplicate_header_column(): void
    {
        $path = $this->csv([['MK', '000123456789', 'Sample', '1059', 'ssm/a.pdf', 'x']], [...self::HEADER, 'variant_id']);

        $this->assertRejected($path, '000123456789', 'duplicate column variant_id');
    }

    public function test_rejects_empty_file(): void
    {
        $this->assertRejected($this->csv([], null), '000123456789', 'CSV file is empty');
    }

    public function test_rejects_empty_required_field(): void
    {
        $path = $this->csv([['MK', '000123456789', '', '1059', 'ssm/a.pdf']]);

        $this->assertRejected($path, '000123456789', 'CSV row 2: subject_name is required');
    }

    public function test_rejects_wrong_field_count(): void
    {
        $path = $this->csv([['MK', '000123456789', 'Sample', '1059']]);

        $this->assertRejected($path, '000123456789', 'CSV row 2: expected 5 fields, got 4');
    }

    public function test_rejects_non_integer_variant(): void
    {
        foreach (['1059.0', ' ', 'abc', '-1059', '1e3'] as $variant) {
            $path = $this->csv([['MK', '000123456789', 'Sample', $variant, 'ssm/a.pdf']]);
            $this->assertRejected($path, '000123456789', 'CSV row 2: variant_id');
        }
    }

    public function test_rejects_unsafe_identification_no(): void
    {
        $path = $this->csv([['MK', '../000123456789', 'Sample', '1059', 'ssm/a.pdf']]);

        $this->assertRejected($path, '000123456789', 'CSV row 2: identification_no');
    }

    public function test_rejects_duplicate_pairs_including_identical_rows(): void
    {
        $row = ['MK', '000123456789', 'Sample', '1059', 'ssm/a.pdf'];

        $this->assertRejected($this->csv([$row, $row]), '000123456789', 'CSV row 3: duplicate IC/variant pair');
        $this->assertRejected(
            $this->csv([$row, ['MK', '000123456789', 'Sample', '1059', 'ssm/other.pdf']]),
            '000123456789',
            'CSV row 3: duplicate IC/variant pair',
        );
    }

    public function test_does_not_coerce_numeric_ic_strings(): void
    {
        $path = $this->csv([
            ['MK', '000123456789', 'Sample', '1059', 'ssm/a.pdf'],
            ['MK', '123456789', 'Other', '1059', 'ssm/b.pdf'],
        ]);

        $result = (new SsmInvolvementCsvReader)->read($path, '123456789');

        $this->assertSame(['000123456789', '123456789'], array_column($result['groups'], 'idValue'));
    }

    public function test_rejects_conflicting_metadata_for_one_ic(): void
    {
        $path = $this->csv([
            ['MK', '000123456789', 'Sample', '1059', 'ssm/a.pdf'],
            ['MK', '000123456789', 'Another Name', '1060', 'ssm/b.pdf'],
        ]);

        $this->assertRejected($path, '000123456789', 'CSV row 3: conflicting metadata for 000123456789');
    }

    public function test_unsupported_variants_warn_and_skip_before_field_validation(): void
    {
        $path = $this->csv([
            ['MK', '000123456789', 'Sample', '1059', 'ssm/a.pdf'],
            ['', '../bad', '', '1045', ''],
            ['MK', '000123456789', 'Different', '1048', 'ssm/c.pdf'],
        ]);

        $result = (new SsmInvolvementCsvReader)->read($path, '000123456789');

        $this->assertSame([
            'CSV row 3: unsupported production variant 1045; skipped',
            'CSV row 4: unsupported production variant 1048; skipped',
        ], $result['warnings']);
        $this->assertCount(1, $result['groups']);
        $this->assertCount(1, $result['groups'][0]['rows']);
    }

    public function test_rejects_absent_default(): void
    {
        $path = $this->csv([['MK', '000123456789', 'Sample', '1059', 'ssm/a.pdf']]);

        $this->assertRejected($path, '900101015555', 'Default IC 900101015555 has no supported row');
        $this->assertRejected($path, '', 'Default IC');
        $this->assertRejected($path, '../x', 'Default IC');
    }

    public function test_rejects_default_with_only_unsupported_rows(): void
    {
        $path = $this->csv([
            ['MK', '000123456789', 'Sample', '1059', 'ssm/a.pdf'],
            ['MK', '900101015555', 'Default', '1045', 'ssm/b.pdf'],
        ]);

        $this->assertRejected($path, '900101015555', 'Default IC 900101015555 has no supported row');
    }

    public function test_null_default_skips_default_check(): void
    {
        $path = $this->csv([['MK', '000123456789', 'Sample', '1059', 'ssm/a.pdf']]);

        $result = (new SsmInvolvementCsvReader)->read($path, null);

        $this->assertSame('000123456789', $result['groups'][0]['idValue']);
    }

    public function test_rejects_unreadable_file(): void
    {
        $this->assertRejected('/nonexistent/involvements.csv', '000123456789', 'Cannot open CSV file');
    }
}
