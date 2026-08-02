<?php

namespace Tests\Unit;

use App\Services\DnbFixtureBuilder;
use Tests\Fakes\FakeS3ObjectFetcher;
use Tests\TestCase;

class DnbFixtureBuilderTest extends TestCase
{
    private string $casePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casePath = sys_get_temp_dir().'/dnb-fixture-builder-test-'.uniqid();
    }

    public function test_assemble_case_writes_meta_with_reg_no_for_singapore(): void
    {
        $xml = '<REPORT><CompanyB2b><CompanyName>Acme Pte Ltd</CompanyName></CompanyB2b></REPORT>';
        $fetcher = new FakeS3ObjectFetcher([
            'my-bucket/dnb/u1/singapore/company/raw/key.xml' => $xml,
        ]);
        $builder = new DnbFixtureBuilder($fetcher);

        $row = (object) [
            'subject_name' => 'Acme Pte Ltd',
            'subject_reg_no' => '201912345A',
            'item' => 'singapore_company_profile',
            'raw_s3_url' => 'https://my-bucket.s3.ap-southeast-5.amazonaws.com/dnb/u1/singapore/company/raw/key.xml',
        ];

        $builder->assembleCase($this->casePath, $row);

        $meta = json_decode(file_get_contents($this->casePath.'/meta.json'), true);
        $this->assertSame(['country' => 'singapore', 'regNo' => '201912345A', 'companyName' => 'Acme Pte Ltd'], $meta);
        $this->assertSame($xml, file_get_contents($this->casePath.'/profile.xml'));
    }

    public function test_assemble_case_writes_meta_with_company_id_for_indonesia(): void
    {
        $xml = '<REPORT><COMPANY><COMPANY_NAME>Acme Indonesia</COMPANY_NAME></COMPANY></REPORT>';
        $fetcher = new FakeS3ObjectFetcher([
            'my-bucket/k.xml' => $xml,
        ]);
        $builder = new DnbFixtureBuilder($fetcher);

        $row = (object) [
            'subject_name' => 'Acme Indonesia',
            'subject_reg_no' => 'ID12345',
            'item' => 'indonesia_company_profile',
            'raw_s3_url' => 'https://my-bucket.s3.ap-southeast-5.amazonaws.com/k.xml',
        ];

        $builder->assembleCase($this->casePath, $row);

        $meta = json_decode(file_get_contents($this->casePath.'/meta.json'), true);
        $this->assertSame(['country' => 'indonesia', 'companyId' => 'ID12345', 'companyName' => 'Acme Indonesia'], $meta);
    }

    public function test_assemble_case_rejects_unsupported_item(): void
    {
        $builder = new DnbFixtureBuilder(new FakeS3ObjectFetcher([]));

        $row = (object) [
            'subject_name' => 'Example Co',
            'subject_reg_no' => '123',
            'item' => 'company_profile_v2',
            'raw_s3_url' => 'https://my-bucket.s3.ap-southeast-5.amazonaws.com/k.xml',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $builder->assembleCase($this->casePath, $row);
    }
}
