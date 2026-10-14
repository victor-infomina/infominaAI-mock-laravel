<?php

namespace Tests\Unit;

use App\Services\SsmCaseCsvReader;
use Tests\TestCase;

class SsmCaseCsvReaderTest extends TestCase
{
    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csvPath = sys_get_temp_dir().'/ssm-case-csv-'.uniqid().'.csv';
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

    public function test_reads_rows_into_fixture_builder_shaped_objects(): void
    {
        $this->write(implode("\n", [
            'company_name,reg_no,entity_type,raw_s3_url,pdf_s3_url',
            'Acme Sdn Bhd,123456-A,Company,https://b.s3.r.amazonaws.com/raw.json,https://b.s3.r.amazonaws.com/report.pdf',
            'Beta Enterprise,SA0001,business,https://b.s3.r.amazonaws.com/biz.json,',
        ]));

        $rows = (new SsmCaseCsvReader('my-bucket', 'ap-southeast-5'))->read($this->csvPath);

        $this->assertCount(2, $rows);
        $this->assertSame('Acme Sdn Bhd', $rows[0]->subject_name);
        $this->assertSame('123456-A', $rows[0]->subject_reg_no);
        $this->assertSame('company', $rows[0]->type);
        $this->assertSame('https://b.s3.r.amazonaws.com/raw.json', $rows[0]->raw_s3_url);
        $this->assertSame('https://b.s3.r.amazonaws.com/report.pdf', $rows[0]->pdf_s3_url);
        $this->assertSame('business', $rows[1]->type);
        $this->assertNull($rows[1]->pdf_s3_url);
    }

    public function test_expands_bare_s3_keys_using_configured_bucket_and_region(): void
    {
        $this->write(implode("\n", [
            'company_name,reg_no,entity_type,raw_s3_url,pdf_s3_url',
            'Acme Sdn Bhd,123456-A,llp,ssm/u1/raw.json,ssm/u1/report.pdf',
        ]));

        $rows = (new SsmCaseCsvReader('my-bucket', 'ap-southeast-5'))->read($this->csvPath);

        $this->assertSame('https://my-bucket.s3.ap-southeast-5.amazonaws.com/ssm/u1/raw.json', $rows[0]->raw_s3_url);
        $this->assertSame('https://my-bucket.s3.ap-southeast-5.amazonaws.com/ssm/u1/report.pdf', $rows[0]->pdf_s3_url);
    }

    public function test_accepts_columns_in_any_order_and_strips_utf8_bom(): void
    {
        $this->write(implode("\n", [
            "\xEF\xBB\xBFreg_no,pdf_s3_url,entity_type,raw_s3_url,company_name",
            '123456-A,,company,https://b.s3.r.amazonaws.com/raw.json,Acme Sdn Bhd',
        ]));

        $rows = (new SsmCaseCsvReader('my-bucket', 'ap-southeast-5'))->read($this->csvPath);

        $this->assertSame('123456-A', $rows[0]->subject_reg_no);
        $this->assertSame('Acme Sdn Bhd', $rows[0]->subject_name);
    }

    public function test_rejects_missing_required_header(): void
    {
        $this->write("company_name,reg_no,raw_s3_url,pdf_s3_url\nAcme,1,x,y\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('entity_type');

        (new SsmCaseCsvReader('my-bucket', 'ap-southeast-5'))->read($this->csvPath);
    }

    public function test_rejects_unknown_entity_type_with_row_number(): void
    {
        $this->write(implode("\n", [
            'company_name,reg_no,entity_type,raw_s3_url,pdf_s3_url',
            'Acme Sdn Bhd,123456-A,company,https://b.s3.r.amazonaws.com/raw.json,',
            'Bad Corp,999,partnership,https://b.s3.r.amazonaws.com/raw.json,',
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('row 3');

        (new SsmCaseCsvReader('my-bucket', 'ap-southeast-5'))->read($this->csvPath);
    }

    public function test_rejects_row_without_raw_s3_url(): void
    {
        $this->write(implode("\n", [
            'company_name,reg_no,entity_type,raw_s3_url,pdf_s3_url',
            'Acme Sdn Bhd,123456-A,company,,',
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('raw_s3_url');

        (new SsmCaseCsvReader('my-bucket', 'ap-southeast-5'))->read($this->csvPath);
    }

    public function test_skips_blank_lines(): void
    {
        $this->write(implode("\n", [
            'company_name,reg_no,entity_type,raw_s3_url,pdf_s3_url',
            '',
            'Acme Sdn Bhd,123456-A,company,https://b.s3.r.amazonaws.com/raw.json,',
            '',
        ]));

        $rows = (new SsmCaseCsvReader('my-bucket', 'ap-southeast-5'))->read($this->csvPath);

        $this->assertCount(1, $rows);
    }
}
