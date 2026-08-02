<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\S3ObjectFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithDevDb;
use Tests\Fakes\FakeS3ObjectFetcher;
use Tests\TestCase;

class SyncCasesTest extends TestCase
{
    use RefreshDatabase, InteractsWithDevDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDevDbSchema();
        config([
            'ssm_mock.s3_bucket' => 'my-bucket',
            'ssm_mock.remote_url' => 'https://remote.example.test',
            'ssm_mock.admin_sync_key' => 'remote-key',
            'ssm_mock.admin_sync_secret' => 'remote-secret',
        ]);
    }

    private function seedRequest(string $regNo, string $name, string $item = 'company_profile_v2', string $rawS3Url = 'https://my-bucket.s3.r.amazonaws.com/raw.json'): string
    {
        $requestId = (string) Str::uuid();
        $transactionId = (string) Str::uuid();
        $datasourceId = (string) Str::uuid();

        DB::connection('be_pgsql')->table('datasource_requests')->insert([
            'id' => $requestId, 'subject_name' => $name, 'subject_reg_no' => $regNo, 'type' => 'company', 'created_at' => now(),
        ]);
        DB::connection('be_pgsql')->table('datasources')->insert([
            'id' => $datasourceId, 'item' => $item, 'source' => 'ssm',
        ]);
        DB::connection('be_pgsql')->table('datasource_transactions')->insert([
            'id' => $transactionId, 'request_id_id' => $requestId,
        ]);
        DB::connection('be_pgsql')->table('datasource_purchased')->insert([
            'id' => (string) Str::uuid(), 'transaction_id_id' => $transactionId,
            'raw_s3_url' => $rawS3Url, 'report_id' => null, 'datasource_id' => $datasourceId,
        ]);

        return $requestId;
    }

    public function test_returns_404_when_local_admin_is_disabled(): void
    {
        config(['ssm_mock.local_admin_enabled' => false]);
        $this->actingAs(User::factory()->create())->get('/admin/sync-cases')->assertNotFound();
    }

    public function test_search_page_lists_matching_entities(): void
    {
        config(['ssm_mock.local_admin_enabled' => true]);
        $this->seedRequest('191001000005', 'HICOM HOLDINGS BERHAD');

        $response = $this->actingAs(User::factory()->create())->get('/admin/sync-cases?q=hicom');

        $response->assertOk();
        $response->assertSee('HICOM HOLDINGS BERHAD');
    }

    public function test_detail_page_shows_idaman_checklist(): void
    {
        config(['ssm_mock.local_admin_enabled' => true]);
        $requestId = $this->seedRequest('191001000005', 'HICOM HOLDINGS BERHAD');
        DB::connection('be_pgsql')->table('idaman_document')->insert([
            'id' => (string) Str::uuid(), 'entity_number' => '191001000005', 'version_id' => 'V1',
            'form' => '557', 'document_date' => '2024-01-01', 's3_url' => 'ssm/k1.json', 'status' => 'READY',
        ]);

        $response = $this->actingAs(User::factory()->create())->get("/admin/sync-cases/{$requestId}");

        $response->assertOk();
        $response->assertSee('V1');
    }

    public function test_sync_action_builds_bundle_and_posts_it_to_the_remote(): void
    {
        config(['ssm_mock.local_admin_enabled' => true]);
        $requestId = $this->seedRequest('191001000005', 'HICOM HOLDINGS BERHAD');
        $this->app->bind(S3ObjectFetcher::class, fn () => new FakeS3ObjectFetcher([
            'my-bucket/raw.json' => '{"getCompProfile":{}}',
        ]));
        Http::fake([
            'https://remote.example.test/admin-api/cases' => Http::response(['caseKey' => 'hicom-holdings-berhad', 'status' => 'synced'], 200),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post("/admin/sync-cases/{$requestId}/sync", ['idaman_version_ids' => []]);

        $response->assertRedirect("/admin/sync-cases/{$requestId}");
        $response->assertSessionHas('syncResult', 'synced: hicom-holdings-berhad');

        Http::assertSent(function ($request) {
            // Note: $request->data() for a multipart request is a numeric list of
            // ['name' => ..., 'contents' => ...] parts, not an associative array
            // keyed by field name — so `isset($request['bundle'])` would always be
            // false here. Check for a part actually named "bundle" instead.
            return $request->url() === 'https://remote.example.test/admin-api/cases'
                && $request->hasHeader('x-Gateway-APIKey', 'remote-key')
                && $request->hasHeader('x-Gateway-APISecret', 'remote-secret')
                && collect($request->data())->firstWhere('name', 'bundle') !== null;
        });
    }

    public function test_sync_action_only_includes_selected_idaman_documents(): void
    {
        config(['ssm_mock.local_admin_enabled' => true]);
        $requestId = $this->seedRequest('191001000005', 'HICOM HOLDINGS BERHAD');

        $envelope = fn (string $content) => json_encode(['getImage' => ['docContent' => base64_encode($content)]]);
        DB::connection('be_pgsql')->table('idaman_document')->insert([
            ['id' => (string) Str::uuid(), 'entity_number' => '191001000005', 'version_id' => 'V1', 'form' => '557', 'document_date' => '2024-01-01', 's3_url' => 'idaman/v1.json', 'status' => 'READY'],
            ['id' => (string) Str::uuid(), 'entity_number' => '191001000005', 'version_id' => 'V2', 'form' => '49', 'document_date' => '2024-02-01', 's3_url' => 'idaman/v2.json', 'status' => 'READY'],
        ]);

        $this->app->bind(S3ObjectFetcher::class, fn () => new FakeS3ObjectFetcher([
            'my-bucket/raw.json' => '{"getCompProfile":{}}',
            'my-bucket/idaman/v1.json' => $envelope('v1-bytes'),
            'my-bucket/idaman/v2.json' => $envelope('v2-bytes'),
        ]));

        $capturedZipPath = null;
        Http::fake(function ($request) use (&$capturedZipPath) {
            $bundle = collect($request->data())->firstWhere('name', 'bundle');
            $capturedZipPath = sys_get_temp_dir().'/captured-'.uniqid().'.zip';
            file_put_contents($capturedZipPath, $bundle['contents']);

            return Http::response(['caseKey' => 'hicom-holdings-berhad', 'status' => 'synced'], 200);
        });

        $this->actingAs(User::factory()->create())
            ->post("/admin/sync-cases/{$requestId}/sync", ['idaman_version_ids' => ['V1']])
            ->assertRedirect("/admin/sync-cases/{$requestId}");

        $zip = new \ZipArchive();
        $zip->open($capturedZipPath);
        $this->assertNotFalse($zip->locateName('hicom-holdings-berhad/idaman/V1.tiff'));
        $this->assertFalse($zip->locateName('hicom-holdings-berhad/idaman/V2.tiff'));
        $zip->close();
    }

    public function test_search_filters_by_gateway(): void
    {
        config(['ssm_mock.local_admin_enabled' => true]);
        $this->seedRequest('0101234567', 'NGUYEN TRADING CO', 'vietnam_company_profile');
        $this->seedRequest('191001000005', 'HICOM HOLDINGS BERHAD');

        $response = $this->actingAs(User::factory()->create())
            ->get('/admin/sync-cases?q=a&gateway=asiaverify');

        $response->assertOk();
        $response->assertSee('NGUYEN TRADING CO');
        $response->assertDontSee('HICOM HOLDINGS BERHAD');
    }

    public function test_search_results_show_source_label(): void
    {
        config(['ssm_mock.local_admin_enabled' => true]);
        $this->seedRequest('0101234567', 'NGUYEN TRADING CO', 'vietnam_company_profile');

        $response = $this->actingAs(User::factory()->create())->get('/admin/sync-cases?q=nguyen');

        $response->assertSee('AsiaVerify — Vietnam');
    }

    public function test_detail_page_hides_idaman_section_for_asiaverify_row(): void
    {
        config(['ssm_mock.local_admin_enabled' => true]);
        $requestId = $this->seedRequest('0101234567', 'NGUYEN TRADING CO', 'vietnam_company_profile');

        $response = $this->actingAs(User::factory()->create())->get("/admin/sync-cases/{$requestId}");

        $response->assertOk();
        $response->assertDontSee('Idaman documents');
    }

    public function test_sync_action_uses_asiaverify_builder_and_posts_gateway_field(): void
    {
        config(['ssm_mock.local_admin_enabled' => true]);
        $requestId = $this->seedRequest(
            '0101234567',
            'NGUYEN TRADING CO',
            'vietnam_company_profile',
            'https://my-bucket.s3.r.amazonaws.com/raw.json',
        );
        $this->app->bind(S3ObjectFetcher::class, fn () => new FakeS3ObjectFetcher([
            'my-bucket/raw.json' => json_encode(['result' => ['companyName' => 'Nguyen Trading Co']]),
        ]));

        $capturedRequest = null;
        Http::fake(function ($request) use (&$capturedRequest) {
            $capturedRequest = $request;

            return Http::response(['caseKey' => 'nguyen-trading-co', 'status' => 'synced'], 200);
        });

        $response = $this->actingAs(User::factory()->create())
            ->post("/admin/sync-cases/{$requestId}/sync", ['idaman_version_ids' => []]);

        $response->assertRedirect("/admin/sync-cases/{$requestId}");
        $response->assertSessionHas('syncResult', 'synced: nguyen-trading-co');
        $this->assertSame('asiaverify', collect($capturedRequest->data())->firstWhere('name', 'gateway')['contents']);

        $zip = new \ZipArchive();
        $bundlePart = collect($capturedRequest->data())->firstWhere('name', 'bundle');
        $tempZip = sys_get_temp_dir().'/captured-'.uniqid().'.zip';
        file_put_contents($tempZip, $bundlePart['contents']);
        $zip->open($tempZip);
        $this->assertNotFalse($zip->locateName('nguyen-trading-co/profile.json'));
        $zip->close();
    }

    public function test_sync_action_uses_dnb_builder_and_posts_gateway_field(): void
    {
        config(['ssm_mock.local_admin_enabled' => true]);
        $requestId = $this->seedRequest(
            '201912345A',
            'ACME PTE LTD',
            'singapore_company_profile',
            'https://my-bucket.s3.r.amazonaws.com/raw.xml',
        );
        $this->app->bind(S3ObjectFetcher::class, fn () => new FakeS3ObjectFetcher([
            'my-bucket/raw.xml' => '<REPORT><CompanyB2b><CompanyName>Acme Pte Ltd</CompanyName></CompanyB2b></REPORT>',
        ]));

        $capturedRequest = null;
        Http::fake(function ($request) use (&$capturedRequest) {
            $capturedRequest = $request;

            return Http::response(['caseKey' => 'acme-pte-ltd', 'status' => 'synced'], 200);
        });

        $response = $this->actingAs(User::factory()->create())
            ->post("/admin/sync-cases/{$requestId}/sync", ['idaman_version_ids' => []]);

        $response->assertRedirect("/admin/sync-cases/{$requestId}");
        $this->assertSame('dnb', collect($capturedRequest->data())->firstWhere('name', 'gateway')['contents']);
    }

    public function test_sync_action_omits_gateway_field_for_ssm_rows(): void
    {
        config(['ssm_mock.local_admin_enabled' => true]);
        $requestId = $this->seedRequest('191001000005', 'HICOM HOLDINGS BERHAD');
        $this->app->bind(S3ObjectFetcher::class, fn () => new FakeS3ObjectFetcher([
            'my-bucket/raw.json' => '{"getCompProfile":{}}',
        ]));

        $capturedRequest = null;
        Http::fake(function ($request) use (&$capturedRequest) {
            $capturedRequest = $request;

            return Http::response(['caseKey' => 'hicom-holdings-berhad', 'status' => 'synced'], 200);
        });

        $this->actingAs(User::factory()->create())
            ->post("/admin/sync-cases/{$requestId}/sync", ['idaman_version_ids' => []])
            ->assertRedirect("/admin/sync-cases/{$requestId}");

        $this->assertNull(collect($capturedRequest->data())->firstWhere('name', 'gateway'));
    }
}
