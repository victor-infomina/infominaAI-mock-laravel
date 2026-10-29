<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WebhookInbox\WebhookInboxRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WebhookInboxAdminTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function settings(): array
    {
        return ['identifier' => null, 'secret' => null, 'response_mode' => 'ok'];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/webhook-inbox')->assertRedirect('/login');
        $this->get('/admin/webhook-inbox/dev')->assertRedirect('/login');
    }

    public function test_admin_sees_the_inbox_list_and_can_create_one(): void
    {
        $admin = User::factory()->create();
        $this->repo->create('existing', $this->settings());

        $this->actingAs($admin)->get('/admin/webhook-inbox')->assertOk()->assertSee('existing');

        $this->actingAs($admin)->post('/admin/webhook-inbox', [
            'slug' => 'dev-myhasil',
            'identifier' => 'client-key',
            'secret' => 'client-secret',
            'response_mode' => 'ok',
        ])->assertRedirect('/admin/webhook-inbox/dev-myhasil');

        $this->assertSame('client-key', $this->repo->find('dev-myhasil')['identifier']);
    }

    public function test_create_validates_slug_and_duplicate(): void
    {
        $admin = User::factory()->create();
        $this->repo->create('taken', $this->settings());

        $this->actingAs($admin)->from('/admin/webhook-inbox')
            ->post('/admin/webhook-inbox', ['slug' => 'Bad Slug', 'response_mode' => 'ok'])
            ->assertRedirect('/admin/webhook-inbox')
            ->assertSessionHasErrors('slug');

        $this->actingAs($admin)->from('/admin/webhook-inbox')
            ->post('/admin/webhook-inbox', ['slug' => 'taken', 'response_mode' => 'ok'])
            ->assertSessionHasErrors('slug');
    }

    public function test_show_lists_events_newest_first_with_delivery_urls_and_masked_secret(): void
    {
        $admin = User::factory()->create();
        $token = $this->repo->create('dev', ['identifier' => 'client-key', 'secret' => 'client-secret', 'response_mode' => 'ok'])['token'];
        $this->repo->storeEvent('dev', ['kind' => 'webhook', 'event_type' => 'bulk-purchase-completed', 'reference_id' => 'REF-OLD', 'hash_status' => 'valid', 'response_status' => 200, 'sequence' => null, 'final' => null, 'retry' => null]);
        $this->repo->storeEvent('dev', ['kind' => 'webhook', 'event_type' => 'extraction-completed', 'reference_id' => 'REF-NEW', 'hash_status' => 'invalid', 'response_status' => 200, 'sequence' => 2, 'final' => true, 'retry' => false]);

        $response = $this->actingAs($admin)->get('/admin/webhook-inbox/dev');

        $response->assertOk();
        $response->assertSeeInOrder(['REF-NEW', 'REF-OLD']);
        $response->assertSee('••••cret');
        $response->assertDontSee('client-secret');
        $response->assertSee(url('/webhook-inbox/dev/'.$token), false);
        $response->assertSee(url('/webhook-inbox/dev/'.$token.'/callback'), false);
    }

    public function test_show_404s_for_unknown_inbox(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/admin/webhook-inbox/nope')->assertNotFound();
    }

    public function test_settings_update_keeps_secret_when_left_blank(): void
    {
        $admin = User::factory()->create();
        $this->repo->create('dev', ['identifier' => 'client-key', 'secret' => 'client-secret', 'response_mode' => 'ok']);

        $this->actingAs($admin)->post('/admin/webhook-inbox/dev/settings', [
            'identifier' => 'client-key-2',
            'secret' => '',
            'response_mode' => 'error',
        ])->assertRedirect('/admin/webhook-inbox/dev');

        $inbox = $this->repo->find('dev');
        $this->assertSame('client-key-2', $inbox['identifier']);
        $this->assertSame('client-secret', $inbox['secret']);
        $this->assertSame('error', $inbox['response_mode']);
    }

    public function test_settings_can_clear_the_secret_explicitly(): void
    {
        $admin = User::factory()->create();
        $this->repo->create('dev', ['identifier' => 'k', 'secret' => 's', 'response_mode' => 'ok']);

        $this->actingAs($admin)->post('/admin/webhook-inbox/dev/settings', [
            'identifier' => 'k',
            'secret' => '',
            'clear_secret' => '1',
            'response_mode' => 'ok',
        ])->assertRedirect('/admin/webhook-inbox/dev');

        $this->assertNull($this->repo->find('dev')['secret']);
    }

    public function test_settings_rejects_unknown_response_mode(): void
    {
        $admin = User::factory()->create();
        $this->repo->create('dev', $this->settings());

        $this->actingAs($admin)->from('/admin/webhook-inbox/dev')
            ->post('/admin/webhook-inbox/dev/settings', ['response_mode' => 'explode'])
            ->assertSessionHasErrors('response_mode');
    }

    public function test_regenerate_token_changes_the_delivery_url(): void
    {
        $admin = User::factory()->create();
        $before = $this->repo->create('dev', $this->settings())['token'];

        $this->actingAs($admin)->post('/admin/webhook-inbox/dev/regenerate-token')
            ->assertRedirect('/admin/webhook-inbox/dev');

        $after = $this->repo->find('dev')['token'];
        $this->assertNotSame($before, $after);
        $this->actingAs($admin)->get('/admin/webhook-inbox/dev')
            ->assertSee(url('/webhook-inbox/dev/'.$after), false)
            ->assertDontSee($before);
    }

    public function test_event_detail_renders_the_stored_json(): void
    {
        $admin = User::factory()->create();
        $this->repo->create('dev', $this->settings());
        $event = $this->repo->storeEvent('dev', ['kind' => 'webhook', 'event_type' => 'bulk-purchase-completed', 'reference_id' => 'REF', 'hash_status' => 'unchecked', 'response_status' => 200, 'body' => ['data' => [['Transaction ID' => 'tx-77']]]]);

        $this->actingAs($admin)->get('/admin/webhook-inbox/dev/events/'.$event['id'])
            ->assertOk()
            ->assertSee('tx-77');

        $this->actingAs($admin)->get('/admin/webhook-inbox/dev/events/nope')->assertNotFound();
    }

    public function test_clear_deletes_events(): void
    {
        $admin = User::factory()->create();
        $this->repo->create('dev', $this->settings());
        $this->repo->storeEvent('dev', ['kind' => 'webhook']);

        $this->actingAs($admin)->post('/admin/webhook-inbox/dev/clear')
            ->assertRedirect('/admin/webhook-inbox/dev');

        $this->assertSame([], $this->repo->events('dev'));
    }

    public function test_nav_shows_webhook_inbox_link_and_tokens_page_describes_admin_sync_as_admin_api(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/tokens')
            ->assertSee('Webhook Inbox')
            ->assertSee('admin_sync — admin API (case sync, webhook inbox reads)');
    }
}
