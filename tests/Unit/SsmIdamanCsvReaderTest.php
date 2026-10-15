<?php

namespace Tests\Unit;

use App\Services\SsmIdamanCsvReader;
use Tests\TestCase;

class SsmIdamanCsvReaderTest extends TestCase
{
    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csvPath = sys_get_temp_dir().'/ssm-idaman-csv-'.uniqid().'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->csvPath);

        parent::tearDown();
    }

    private function write(string $csv): void
    {
        file_put_contents($this->csvPath, $csv);
    }

    public function test_groups_rows_by_reg_no_in_fixture_builder_shape(): void
    {
        $this->write(implode("\n", [
            'reg_no,version_id,form,document_date,s3_url',
            '123456-A,V1,557,2024-01-01,ssm/u1/idaman/123456-A_V1.json',
            '123456-A,V2,24,2023-06-30,ssm/u1/idaman/123456-A_V2.json',
            'SA0001,V9,D,2022-02-02,ssm/u2/idaman/SA0001_V9.json',
        ]));

        $byRegNo = (new SsmIdamanCsvReader)->read($this->csvPath);

        $this->assertSame(['123456-A', 'SA0001'], array_keys($byRegNo));
        $this->assertCount(2, $byRegNo['123456-A']);
        $this->assertSame('V1', $byRegNo['123456-A'][0]->version_id);
        $this->assertSame('557', $byRegNo['123456-A'][0]->form);
        $this->assertSame('2024-01-01', $byRegNo['123456-A'][0]->document_date);
        $this->assertSame('ssm/u1/idaman/123456-A_V1.json', $byRegNo['123456-A'][0]->s3_url);
        $this->assertSame('V9', $byRegNo['SA0001'][0]->version_id);
    }

    public function test_reduces_full_s3_url_to_bare_object_key(): void
    {
        $this->write(implode("\n", [
            'reg_no,version_id,form,document_date,s3_url',
            '123456-A,V1,557,2024-01-01,https://my-bucket.s3.ap-southeast-5.amazonaws.com/ssm/u1/idaman/123456-A%20V1.json',
        ]));

        $byRegNo = (new SsmIdamanCsvReader)->read($this->csvPath);

        $this->assertSame('ssm/u1/idaman/123456-A V1.json', $byRegNo['123456-A'][0]->s3_url);
    }

    public function test_tolerates_bom_mixed_case_header_and_blank_lines(): void
    {
        $this->write("\xEF\xBB\xBF".implode("\n", [
            'Reg_No,Version_ID,Form,Document_Date,S3_URL',
            '',
            '123456-A,V1,557,2024-01-01,k.json',
            '',
        ]));

        $byRegNo = (new SsmIdamanCsvReader)->read($this->csvPath);

        $this->assertCount(1, $byRegNo['123456-A']);
    }

    public function test_rejects_missing_columns(): void
    {
        $this->write("reg_no,version_id\n123456-A,V1\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('form, document_date, s3_url');

        (new SsmIdamanCsvReader)->read($this->csvPath);
    }

    public function test_rejects_row_with_empty_required_field(): void
    {
        $this->write("reg_no,version_id,form,document_date,s3_url\n123456-A,,557,2024-01-01,k.json\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('row 2: version_id is required');

        (new SsmIdamanCsvReader)->read($this->csvPath);
    }

    public function test_rejects_unopenable_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SsmIdamanCsvReader)->read('/nonexistent/idaman.csv');
    }
}
