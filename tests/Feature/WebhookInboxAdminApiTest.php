<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Services\WebhookInbox\WebhookInboxRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WebhookInboxAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private WebhookInboxRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/webhook-inbox-test-'.uniqid();
        config(['ssm_mock.webhook_inbox_path' => $this->root]);
        $this->repo = app(WebhookInboxRepository::class);
        $this->repo->create('dev', ['identifier' => null, 'secret' => null, 'response_mode' => 'ok']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** Same header pair the case-sync tool sends (see CaseUploadTest). */
    private function authHeaders(string $purpose = 'admin_sync'): array
    {
        [$apiClient, $secret] = ApiClient::createWithSecret('inbox-reader', null, $purpose);

        return [
            'x-Gateway-APIKey' => $apiClient->key,
            'x-Gateway-APISecret' => $secret,
        ];
    }

    public function test_missing_credentials_are_rejected(): void
    {
        $this->getJson('/admin-api/webhook-inbox/dev/events')->assertStatus(401);
    }

    public function test_gateway_purpose_credential_is_rejected(): void
    {
        $this->withHeaders($this->authHeaders('gateway'))
            ->getJson('/admin-api/webhook-inbox/dev/events')
            ->assertStatus(401);
    }

    public function test_unknown_inbox_is_404(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/admin-api/webhook-inbox/nope/events')
            ->assertNotFound();
    }

    public function test_lists_events_newest_first(): void
    {
        $this->repo->storeEvent('dev', ['kind' => 'webhook', 'reference_id' => 'OLD']);
        $this->repo->storeEvent('dev', ['kind' => 'webhook', 'reference_id' => 'NEW']);

        $response = $this->withHeaders($this->authHeaders())->getJson('/admin-api/webhook-inbox/dev/events');

        $response->assertOk()
            ->assertJsonPath('inbox', 'dev')
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.reference_id', 'NEW')
            ->assertJsonPath('events.1.reference_id', 'OLD');
    }

    public function test_filters_by_since_and_reference_id(): void
    {
        $this->repo->storeEvent('dev', ['kind' => 'webhook', 'reference_id' => 'A', 'received_at' => '2026-10-29T10:00:00+00:00']);
        $this->repo->storeEvent('dev', ['kind' => 'webhook', 'reference_id' => 'B', 'received_at' => '2026-10-29T11:00:00+00:00']);
        $this->repo->storeEvent('dev', ['kind' => 'webhook', 'reference_id' => 'A', 'received_at' => '2026-10-29T12:00:00+00:00']);

        $this->withHeaders($this->authHeaders())
            ->getJson('/admin-api/webhook-inbox/dev/events?since=2026-10-29T11:00:00Z')
            ->assertOk()->assertJsonCount(2, 'events');

        $this->withHeaders($this->authHeaders())
            ->getJson('/admin-api/webhook-inbox/dev/events?reference_id=A')
            ->assertOk()->assertJsonCount(2, 'events');

        $this->withHeaders($this->authHeaders())
            ->getJson('/admin-api/webhook-inbox/dev/events?since=2026-10-29T11:00:00Z&reference_id=A')
            ->assertOk()->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.received_at', '2026-10-29T12:00:00+00:00');
    }
}
