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
        file_put_contents($this->casePath.'/meta.json', json_encode(['regNo' => '123456-A', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']));

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
        $this->assertCount(1, $list);
        $entry = $list[0];
        // The real gateway's documentInfos entry shape: the BE and FE read documentDate (YYYYMMDD).
        $this->assertSame('V1', $entry['verId']);
        $this->assertSame('557', $entry['formType']);
        $this->assertSame('20240101', $entry['documentDate']);
        $this->assertSame('20240101', $entry['receivedDate']);
        $this->assertSame('2024-01-01T00:00:00+08:00', $entry['dateFiler']);
        $this->assertSame('123456-A', $entry['companyNo']);
        $this->assertSame('V1.TIF', $entry['imageName']);
        $this->assertSame('1', $entry['totalPage']);
        $this->assertSame('', $entry['comments']);
        $this->assertSame('', $entry['stateCode']);
        $this->assertSame('', $entry['sourceData']);
        $this->assertNotEmpty($entry['batchId']);
        $this->assertSame('raw-tiff-bytes', file_get_contents($this->casePath.'/idaman/V1.tiff'));
    }

    public function test_assemble_idaman_documents_is_noop_for_empty_list(): void
    {
        $builder = new SsmFixtureBuilder(new FakeS3ObjectFetcher([]));
        mkdir($this->casePath, 0777, true);

        $builder->assembleIdamanDocuments($this->casePath, [], 'my-bucket');

        $this->assertFalse(is_dir($this->casePath.'/idaman'));
    }

    public function test_assemble_idaman_documents_accepts_datetime_document_date_from_the_dev_db(): void
    {
        $builder = new SsmFixtureBuilder(new FakeS3ObjectFetcher([]));
        mkdir($this->casePath, 0777, true);
        file_put_contents($this->casePath.'/meta.json', json_encode(['regNo' => '123456-A', 'companyName' => 'Acme', 'entityType' => 'company']));

        $builder->assembleIdamanDocuments($this->casePath, [
            (object) ['version_id' => 'V1', 'form' => '557', 'document_date' => '2024-01-01 00:00:00', 's3_url' => null],
        ], 'my-bucket');

        $entry = json_decode(file_get_contents($this->casePath.'/idaman/list.json'), true)[0];
        $this->assertSame('20240101', $entry['documentDate']);
        $this->assertSame('2024-01-01T00:00:00+08:00', $entry['dateFiler']);
    }
}
