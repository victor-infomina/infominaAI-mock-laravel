<?php

namespace Tests\Unit;

use App\Services\WebhookInbox\WebhookInboxRepository;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class WebhookInboxRepositoryTest extends TestCase
{
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

    private function settings(string $mode = 'ok'): array
    {
        return ['identifier' => null, 'secret' => null, 'response_mode' => $mode];
    }

    public function test_create_writes_config_with_a_token_and_find_reads_it_back(): void
    {
        $inbox = $this->repo->create('dev-myhasil', [
            'identifier' => 'client-key',
            'secret' => 'client-secret',
            'response_mode' => 'ok',
        ]);

        $this->assertSame('dev-myhasil', $inbox['slug']);
        $this->assertMatchesRegularExpression(WebhookInboxRepository::TOKEN_PATTERN, $inbox['token']);
        $this->assertFileExists($this->root.'/dev-myhasil/config.json');

        $found = $this->repo->find('dev-myhasil');
        $this->assertSame('client-key', $found['identifier']);
        $this->assertSame('ok', $found['response_mode']);
        $this->assertSame($inbox['token'], $found['token']);
        $this->assertNotEmpty($found['created_at']);
    }

    public function test_find_by_token_requires_an_exact_match(): void
    {
        $inbox = $this->repo->create('dev', $this->settings());

        $this->assertSame('dev', $this->repo->findByToken('dev', $inbox['token'])['slug']);
        $this->assertNull($this->repo->findByToken('dev', str_repeat('0', 32)));
        $this->assertNull($this->repo->findByToken('dev', ''));
        $this->assertNull($this->repo->findByToken('missing', $inbox['token']));
    }

    public function test_regenerate_token_replaces_the_token(): void
    {
        $before = $this->repo->create('dev', $this->settings())['token'];

        $after = $this->repo->regenerateToken('dev')['token'];

        $this->assertNotSame($before, $after);
        $this->assertMatchesRegularExpression(WebhookInboxRepository::TOKEN_PATTERN, $after);
        $this->assertNull($this->repo->findByToken('dev', $before));
        $this->assertNotNull($this->repo->findByToken('dev', $after));
    }

    public function test_create_rejects_bad_slug(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->create('Bad Slug!', $this->settings());
    }

    public function test_create_rejects_existing_inbox(): void
    {
        $this->repo->create('dup', $this->settings());

        $this->expectException(InvalidArgumentException::class);
        $this->repo->create('dup', $this->settings());
    }

    public function test_create_rejects_unknown_response_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->create('x', $this->settings('explode'));
    }

    public function test_find_returns_null_for_missing_or_corrupt_config(): void
    {
        $this->assertNull($this->repo->find('nope'));

        File::ensureDirectoryExists($this->root.'/broken');
        File::put($this->root.'/broken/config.json', '{not json');
        $this->assertNull($this->repo->find('broken'));

        File::ensureDirectoryExists($this->root.'/empty');
        $this->assertNull($this->repo->find('empty'));
    }

    public function test_all_lists_inboxes_sorted_by_slug(): void
    {
        $this->repo->create('zeta', $this->settings());
        $this->repo->create('alpha', $this->settings());

        $this->assertSame(['alpha', 'zeta'], array_column($this->repo->all(), 'slug'));
    }

    public function test_update_settings_merges_and_keeps_the_token(): void
    {
        $token = $this->repo->create('dev', ['identifier' => 'k', 'secret' => 's', 'response_mode' => 'ok'])['token'];

        $updated = $this->repo->updateSettings('dev', ['response_mode' => 'error']);

        $this->assertSame('error', $updated['response_mode']);
        $this->assertSame('k', $updated['identifier']);
        $this->assertSame('s', $updated['secret']);
        $this->assertSame($token, $updated['token']);
    }

    public function test_update_settings_requires_existing_inbox(): void
    {
        $this->expectException(RuntimeException::class);
        $this->repo->updateSettings('missing', ['response_mode' => 'ok']);
    }

    public function test_store_event_assigns_id_and_received_at_and_lists_newest_first(): void
    {
        $this->repo->create('dev', $this->settings());

        $first = $this->repo->storeEvent('dev', ['kind' => 'webhook', 'reference_id' => 'r1']);
        $second = $this->repo->storeEvent('dev', ['kind' => 'webhook', 'reference_id' => 'r2']);

        $this->assertNotEmpty($first['id']);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $first['received_at']);

        $events = $this->repo->events('dev');
        $this->assertCount(2, $events);
        $this->assertSame('r2', $events[0]['reference_id']);
        $this->assertSame('r1', $events[1]['reference_id']);
        $this->assertSame($first, $this->repo->findEvent('dev', $first['id']));
        $this->assertNull($this->repo->findEvent('dev', 'nope'));
    }

    public function test_events_can_be_filtered_by_since_and_reference_id(): void
    {
        $this->repo->create('dev', $this->settings());
        $this->repo->storeEvent('dev', ['kind' => 'webhook', 'reference_id' => 'A', 'received_at' => '2026-10-29T10:00:00+00:00']);
        $this->repo->storeEvent('dev', ['kind' => 'webhook', 'reference_id' => 'B', 'received_at' => '2026-10-29T11:00:00+00:00']);
        $this->repo->storeEvent('dev', ['kind' => 'webhook', 'reference_id' => 'A', 'received_at' => '2026-10-29T12:00:00+00:00']);

        $this->assertCount(2, $this->repo->events('dev', '2026-10-29T11:00:00Z'));
        $this->assertCount(2, $this->repo->events('dev', null, 'A'));
        $this->assertCount(1, $this->repo->events('dev', '2026-10-29T11:00:00Z', 'A'));
        $this->assertSame('A', $this->repo->events('dev', '2026-10-29T11:00:00Z', 'A')[0]['reference_id']);
        $this->assertCount(3, $this->repo->events('dev', 'not-a-date'));
    }

    public function test_store_event_purges_files_older_than_seven_days(): void
    {
        $this->repo->create('dev', $this->settings());
        $old = $this->root.'/dev/events/20200101000000000000-old.json';
        File::put($old, json_encode(['id' => 'old', 'received_at' => '2020-01-01T00:00:00+00:00']));
        touch($old, strtotime('-8 days'));

        $this->repo->storeEvent('dev', ['kind' => 'webhook']);

        $this->assertFileDoesNotExist($old);
        $this->assertCount(1, $this->repo->events('dev'));
    }

    public function test_store_event_drops_the_oldest_beyond_max_events(): void
    {
        $this->repo->create('dev', $this->settings());
        $dir = $this->root.'/dev/events';
        for ($i = 0; $i < WebhookInboxRepository::MAX_EVENTS; $i++) {
            File::put(sprintf('%s/20260101%06d000000-%d.json', $dir, $i, $i), json_encode(['id' => (string) $i]));
        }

        $this->repo->storeEvent('dev', ['kind' => 'webhook', 'id' => 'newest']);

        $events = $this->repo->events('dev');
        $this->assertCount(WebhookInboxRepository::MAX_EVENTS, $events);
        $this->assertSame('newest', $events[0]['id']);
        $this->assertFileDoesNotExist($dir.'/20260101000000000000-0.json');
    }

    public function test_clear_deletes_all_events_and_reports_count(): void
    {
        $this->repo->create('dev', $this->settings());
        $this->repo->storeEvent('dev', ['kind' => 'webhook']);
        $this->repo->storeEvent('dev', ['kind' => 'webhook']);

        $this->assertSame(2, $this->repo->clear('dev'));
        $this->assertSame([], $this->repo->events('dev'));
        $this->assertNotNull($this->repo->find('dev'));
    }
}
