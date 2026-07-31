<?php

namespace Tests\Unit;

use App\Services\SsmFixtureBuilder;
use Tests\Fakes\FakeS3ObjectFetcher;
use Tests\TestCase;

class SsmFixtureBuilderTest extends TestCase
{
    private string $casePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casePath = sys_get_temp_dir().'/ssm-fixture-builder-test-'.uniqid();
    }

    public function test_assemble_case_writes_profile_report_and_meta(): void
    {
        $fetcher = new FakeS3ObjectFetcher([
            'my-bucket/raw/key.json' => '{"getCompProfile":{"companyName":"Acme"}}',
            'my-bucket/reports/key.pdf' => '%PDF-1.4 fake report bytes',
        ]);
        $builder = new SsmFixtureBuilder($fetcher);

        $row = (object) [
            'id' => 'req-1',
            'subject_name' => 'Acme Sdn Bhd',
            'subject_reg_no' => '123456-A',
            'type' => 'company',
            'raw_s3_url' => 'https://my-bucket.s3.ap-southeast-5.amazonaws.com/raw/key.json',
            'pdf_s3_url' => 'https://my-bucket.s3.ap-southeast-5.amazonaws.com/reports/key.pdf',
        ];

        $builder->assembleCase($this->casePath, $row);

        $this->assertJsonStringEqualsJsonFile(
            $this->casePath.'/company-profile.json',
            '{"getCompProfile":{"companyName":"Acme"}}',
        );
        $this->assertSame('%PDF-1.4 fake report bytes', file_get_contents($this->casePath.'/report.pdf'));
        $meta = json_decode(file_get_contents($this->casePath.'/meta.json'), true);
        $this->assertSame(['regNo' => '123456-A', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company'], $meta);
    }

    public function test_assemble_idaman_documents_writes_list_and_decoded_content(): void
    {
        $envelope = json_encode(['getImage' => ['docContent' => base64_encode('raw-tiff-bytes')]]);
        $fetcher = new FakeS3ObjectFetcher([
            'my-bucket/ssm/u1/idaman/123456-A_idaman_doc_V1.json' => $envelope,
        ]);
        $builder = new SsmFixtureBuilder($fetcher);
        mkdir($this->casePath, 0777, true);

        $idamanRows = [
            (object) [
                'version_id' => 'V1',
                'form' => '557',
                'document_date' => '2024-01-01',
                's3_url' => 'ssm/u1/idaman/123456-A_idaman_doc_V1.json',
            ],
        ];

        $builder->assembleIdamanDocuments($this->casePath, $idamanRows, 'my-bucket');

        $list = json_decode(file_get_contents($this->casePath.'/idaman/list.json'), true);
        $this->assertSame([['verId' => 'V1', 'formType' => '557', 'dateFiler' => '2024-01-01']], $list);
        $this->assertSame('raw-tiff-bytes', file_get_contents($this->casePath.'/idaman/V1.tiff'));
    }

    public function test_assemble_idaman_documents_is_noop_for_empty_list(): void
    {
        $builder = new SsmFixtureBuilder(new FakeS3ObjectFetcher([]));
        mkdir($this->casePath, 0777, true);

        $builder->assembleIdamanDocuments($this->casePath, [], 'my-bucket');

        $this->assertFalse(is_dir($this->casePath.'/idaman'));
    }
}
