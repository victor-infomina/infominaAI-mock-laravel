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

    private function seedRequest(string $regNo, string $name): string
    {
        $requestId = (string) Str::uuid();
        $transactionId = (string) Str::uuid();

        DB::connection('be_pgsql')->table('datasource_requests')->insert([
            'id' => $requestId, 'subject_name' => $name, 'subject_reg_no' => $regNo, 'type' => 'company', 'created_at' => now(),
        ]);
        DB::connection('be_pgsql')->table('datasource_transactions')->insert([
            'id' => $transactionId, 'request_id_id' => $requestId,
        ]);
        DB::connection('be_pgsql')->table('datasource_purchased')->insert([
            'id' => (string) Str::uuid(), 'transaction_id_id' => $transactionId,
            'raw_s3_url' => 'https://my-bucket.s3.r.amazonaws.com/raw.json', 'report_id' => null,
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
}
