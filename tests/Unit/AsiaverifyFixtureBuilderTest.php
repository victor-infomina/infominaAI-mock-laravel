<?php

namespace Tests\Unit;

use App\Services\AsiaverifyFixtureBuilder;
use Tests\Fakes\FakeS3ObjectFetcher;
use Tests\TestCase;

class AsiaverifyFixtureBuilderTest extends TestCase
{
    private string $casePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casePath = sys_get_temp_dir().'/asiaverify-fixture-builder-test-'.uniqid();
    }

    public function test_assemble_case_writes_meta_and_unwrapped_profile(): void
    {
        $fetcher = new FakeS3ObjectFetcher([
            'my-bucket/asiaverify/u1/vietnam/company/raw/key.json' => json_encode([
                'code' => '200',
                'message' => 'Request succeeded',
                'result' => ['companyId' => '0101234567', 'companyName' => 'Nguyen Trading Co'],
                'lastUpdated' => '2024-01-01 00:00:00',
                'orderNo' => 'av123',
            ]),
        ]);
        $builder = new AsiaverifyFixtureBuilder($fetcher);

        $row = (object) [
            'subject_name' => 'Nguyen Trading Co',
            'subject_reg_no' => '0101234567',
            'item' => 'vietnam_company_profile',
            'raw_s3_url' => 'https://my-bucket.s3.ap-southeast-5.amazonaws.com/asiaverify/u1/vietnam/company/raw/key.json',
        ];

        $builder->assembleCase($this->casePath, $row);

        $meta = json_decode(file_get_contents($this->casePath.'/meta.json'), true);
        $this->assertSame(['country' => 'VNM', 'companyId' => '0101234567', 'companyName' => 'Nguyen Trading Co'], $meta);

        $profile = json_decode(file_get_contents($this->casePath.'/profile.json'), true);
        $this->assertSame(['companyId' => '0101234567', 'companyName' => 'Nguyen Trading Co'], $profile);
    }

    public function test_assemble_case_maps_thailand_and_china(): void
    {
        $fetcher = new FakeS3ObjectFetcher([
            'my-bucket/k.json' => json_encode(['result' => ['companyName' => 'Example Co']]),
        ]);

        $thBuilder = new AsiaverifyFixtureBuilder($fetcher);
        $thRow = (object) [
            'subject_name' => 'Example Co', 'subject_reg_no' => '123',
            'item' => 'thailand_company_profile',
            'raw_s3_url' => 'https://my-bucket.s3.ap-southeast-5.amazonaws.com/k.json',
        ];
        $thBuilder->assembleCase($this->casePath.'-th', $thRow);
        $thMeta = json_decode(file_get_contents($this->casePath.'-th/meta.json'), true);
        $this->assertSame('THA', $thMeta['country']);

        $cnBuilder = new AsiaverifyFixtureBuilder($fetcher);
        $cnRow = (object) [
            'subject_name' => 'Example Co', 'subject_reg_no' => '123',
            'item' => 'china_company_profile',
            'raw_s3_url' => 'https://my-bucket.s3.ap-southeast-5.amazonaws.com/k.json',
        ];
        $cnBuilder->assembleCase($this->casePath.'-cn', $cnRow);
        $cnMeta = json_decode(file_get_contents($this->casePath.'-cn/meta.json'), true);
        $this->assertSame('CHN', $cnMeta['country']);
    }

    public function test_assemble_case_rejects_unsupported_item(): void
    {
        $builder = new AsiaverifyFixtureBuilder(new FakeS3ObjectFetcher([]));

        $row = (object) [
            'subject_name' => 'Example Co',
            'subject_reg_no' => '123',
            'item' => 'company_profile_v2',
            'raw_s3_url' => 'https://my-bucket.s3.ap-southeast-5.amazonaws.com/k.json',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $builder->assembleCase($this->casePath, $row);
    }
}
