<?php

namespace Tests\Unit;

use App\Services\DevDbEntityRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithDevDb;
use Tests\TestCase;

class DevDbEntityRepositoryTest extends TestCase
{
    use InteractsWithDevDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDevDbSchema();
    }

    private function seedRequest(string $id, string $name, string $regNo, string $type, ?string $rawS3Url): void
    {
        DB::connection('be_pgsql')->table('datasource_requests')->insert([
            'id' => $id,
            'subject_name' => $name,
            'subject_reg_no' => $regNo,
            'type' => $type,
            'created_at' => now(),
        ]);

        if ($rawS3Url === null) {
            return;
        }

        $transactionId = (string) Str::uuid();
        DB::connection('be_pgsql')->table('datasource_transactions')->insert([
            'id' => $transactionId,
            'request_id_id' => $id,
        ]);
        DB::connection('be_pgsql')->table('datasource_purchased')->insert([
            'id' => (string) Str::uuid(),
            'transaction_id_id' => $transactionId,
            'raw_s3_url' => $rawS3Url,
            'report_id' => null,
        ]);
    }

    public function test_search_matches_by_name_and_filters_by_type(): void
    {
        $this->seedRequest((string) Str::uuid(), 'HICOM HOLDINGS BERHAD', '191001000005', 'company', 'https://b.s3.r.amazonaws.com/k1.json');
        $this->seedRequest((string) Str::uuid(), 'HICOM TRADING SDN BHD', '191001000006', 'business', 'https://b.s3.r.amazonaws.com/k2.json');

        $repo = new DevDbEntityRepository();
        $result = $repo->search('hicom', 'company', 1, 20);

        $this->assertSame(1, $result['total']);
        $this->assertSame('HICOM HOLDINGS BERHAD', $result['items'][0]->subject_name);
    }

    public function test_search_excludes_requests_with_no_raw_s3_url(): void
    {
        $this->seedRequest((string) Str::uuid(), 'NO DATA YET BERHAD', '000000000001', 'company', null);

        $repo = new DevDbEntityRepository();
        $result = $repo->search('no data', null, 1, 20);

        $this->assertSame(0, $result['total']);
    }

    public function test_search_matches_by_reg_no(): void
    {
        $this->seedRequest((string) Str::uuid(), 'HICOM HOLDINGS BERHAD', '191001000005', 'company', 'https://b.s3.r.amazonaws.com/k1.json');

        $repo = new DevDbEntityRepository();
        $result = $repo->search('191001000005', null, 1, 20);

        $this->assertSame(1, $result['total']);
    }

    public function test_find_idaman_documents_filters_ready_and_orders_by_date_desc(): void
    {
        DB::connection('be_pgsql')->table('idaman_document')->insert([
            ['id' => (string) Str::uuid(), 'entity_number' => '191001000005', 'version_id' => 'V1', 'form' => '557', 'document_date' => '2023-01-01', 's3_url' => 'ssm/k1.json', 'status' => 'READY'],
            ['id' => (string) Str::uuid(), 'entity_number' => '191001000005', 'version_id' => 'V2', 'form' => '49', 'document_date' => '2024-01-01', 's3_url' => 'ssm/k2.json', 'status' => 'READY'],
            ['id' => (string) Str::uuid(), 'entity_number' => '191001000005', 'version_id' => 'V3', 'form' => '49', 'document_date' => '2024-06-01', 's3_url' => null, 'status' => 'NEW'],
            ['id' => (string) Str::uuid(), 'entity_number' => 'other-reg-no', 'version_id' => 'V4', 'form' => '49', 'document_date' => '2024-06-01', 's3_url' => 'ssm/k4.json', 'status' => 'READY'],
        ]);

        $repo = new DevDbEntityRepository();
        $documents = $repo->findIdamanDocuments('191001000005');

        $this->assertCount(2, $documents);
        $this->assertSame('V2', $documents[0]->version_id);
        $this->assertSame('V1', $documents[1]->version_id);
    }
}
