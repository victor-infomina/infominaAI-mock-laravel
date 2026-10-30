<?php

namespace Tests\Feature;

use App\Services\WebhookInbox\WebhookHashVerifier;
use App\Services\WebhookInbox\WebhookInboxRepository;
use App\Services\WebhookInbox\WebhookSignatureVerifier;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WebhookInboxReceiveTest extends TestCase
{
    private string $root;

    private WebhookInboxRepository $repo;

    private string $token;

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

    private function createInbox(string $mode = 'ok', ?string $identifier = 'client-key', ?string $secret = 'client-secret'): void
    {
        $this->token = $this->repo->create('dev-myhasil', ['identifier' => $identifier, 'secret' => $secret, 'response_mode' => $mode])['token'];
    }

    private function url(?string $token = null): string
    {
        return '/webhook-inbox/dev-myhasil/'.($token ?? $this->token);
    }

    public function test_unknown_inbox_returns_404(): void
    {
        $this->postJson('/webhook-inbox/nope/'.str_repeat('a', 32), ['referenceId' => 'R'])->assertNotFound();
    }

    public function test_wrong_token_returns_404_and_stores_nothing(): void
    {
        $this->createInbox();

        $this->postJson($this->url(str_repeat('0', 32)), ['referenceId' => 'R'])->assertNotFound();
        $this->postJson('/webhook-inbox/dev-myhasil', ['referenceId' => 'R'])->assertNotFound();

        $this->assertSame([], $this->repo->events('dev-myhasil'));
    }

    public function test_bulk_purchase_completed_is_stored_with_a_valid_hash(): void
    {
        $this->createInbox();
        $hash = WebhookHashVerifier::compute('client-key', 'client-secret', 'REF-1');

        $response = $this->postJson($this->url(), [
            'referenceId' => 'REF-1',
            'hash' => $hash,
            'template' => 'ssm-idaman-document',
            'data' => [['Transaction ID' => 'tx-1']],
        ]);

        $response->assertOk()->assertJson(['received' => true]);
        $events = $this->repo->events('dev-myhasil');
        $this->assertCount(1, $events);
        $this->assertSame('webhook', $events[0]['kind']);
        $this->assertSame('bulk-purchase-completed', $events[0]['event_type']);
        $this->assertSame('REF-1', $events[0]['reference_id']);
        $this->assertSame('valid', $events[0]['hash_status']);
        $this->assertSame(200, $events[0]['response_status']);
        $this->assertSame('ok', $events[0]['response_mode']);
        $this->assertSame('tx-1', $events[0]['body']['data'][0]['Transaction ID']);
        $this->assertStringStartsWith('application/json', $events[0]['headers']['content-type']);
    }

    public function test_extraction_completed_fields_are_lifted_from_the_body(): void
    {
        $this->createInbox();

        $this->postJson($this->url(), [
            'event' => 'extraction-completed',
            'eventId' => 'e-1',
            'referenceId' => 'REF-2',
            'hash' => 'wrong',
            'sequence' => 3,
            'final' => true,
            'retry' => false,
            'data' => [],
        ])->assertOk();

        $event = $this->repo->events('dev-myhasil')[0];
        $this->assertSame('extraction-completed', $event['event_type']);
        $this->assertSame(3, $event['sequence']);
        $this->assertTrue($event['final']);
        $this->assertFalse($event['retry']);
        $this->assertSame('invalid', $event['hash_status']);
    }

    public function test_hash_is_unchecked_when_the_inbox_has_no_secret(): void
    {
        $this->createInbox('ok', null, null);

        $this->postJson($this->url(), ['referenceId' => 'R', 'hash' => 'abc'])->assertOk();

        $this->assertSame('unchecked', $this->repo->events('dev-myhasil')[0]['hash_status']);
    }

    public function test_non_json_body_is_stored_raw_and_still_acknowledged(): void
    {
        $this->createInbox();

        $this->call('POST', $this->url(), [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'hello')
            ->assertOk();

        $event = $this->repo->events('dev-myhasil')[0];
        $this->assertSame(['raw' => 'hello'], $event['body']);
        $this->assertNull($event['reference_id']);
        $this->assertSame('unchecked', $event['hash_status']);
    }

    public function test_oversized_body_is_rejected_with_413_and_not_stored(): void
    {
        $this->createInbox();
        $limit = WebhookInboxRepository::MAX_BODY_BYTES;

        $this->call('POST', $this->url(), [], [], [], ['CONTENT_TYPE' => 'text/plain'], str_repeat('x', $limit + 1))
            ->assertStatus(413);
        $this->assertSame([], $this->repo->events('dev-myhasil'));

        $this->call('POST', $this->url(), [], [], [], ['CONTENT_TYPE' => 'text/plain'], str_repeat('x', $limit))
            ->assertOk();
        $this->assertCount(1, $this->repo->events('dev-myhasil'));
    }

    public function test_error_mode_returns_500_but_still_records_the_event(): void
    {
        $this->createInbox('error');

        $this->postJson($this->url(), ['referenceId' => 'R'])
            ->assertStatus(500)
            ->assertJson(['received' => false]);

        $event = $this->repo->events('dev-myhasil')[0];
        $this->assertSame(500, $event['response_status']);
        $this->assertSame('error', $event['response_mode']);
    }

    public function test_timeout_mode_returns_200_after_the_configured_delay(): void
    {
        $this->createInbox('timeout');
        $this->assertSame(0, config('ssm_mock.webhook_inbox_timeout_seconds'));

        $this->postJson($this->url(), ['referenceId' => 'R'])->assertOk();

        $this->assertSame('timeout', $this->repo->events('dev-myhasil')[0]['response_mode']);
    }

    public function test_invalid_utf8_body_is_still_recorded(): void
    {
        $this->createInbox();

        $this->call('POST', $this->url(), [], [], [], ['CONTENT_TYPE' => 'text/plain'], "abc\xff\xfe")
            ->assertOk();

        $events = $this->repo->events('dev-myhasil');
        $this->assertCount(1, $events);
        $this->assertStringStartsWith('abc', $events[0]['body']['raw']);
    }

    public function test_non_scalar_sequence_and_non_bool_flags_are_not_stored_as_is(): void
    {
        $this->createInbox();

        $this->postJson($this->url(), ['referenceId' => 'R', 'sequence' => ['a' => 1], 'final' => 'no', 'retry' => 1])
            ->assertOk();

        $event = $this->repo->events('dev-myhasil')[0];
        $this->assertNull($event['sequence']);
        $this->assertNull($event['final']);
        $this->assertNull($event['retry']);
        $this->assertSame(['a' => 1], $event['body']['sequence']);
    }

    private function postSigned(string $raw, ?string $signature, ?string $eventId = 'evt-1')
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_SIGNATURE'] = $signature;
        }
        if ($eventId !== null) {
            $server['HTTP_X_EVENT_ID'] = $eventId;
        }

        return $this->call('POST', $this->url(), [], [], [], $server, $raw);
    }

    public function test_signed_webhook_without_hash_is_stored_with_a_valid_signature(): void
    {
        $this->createInbox();
        $raw = '{"referenceId":"REF-1","template":"ssm-idaman-document","data":[]}';

        $this->postSigned($raw, WebhookSignatureVerifier::signWebhook('client-secret', $raw, time()))
            ->assertOk()->assertJson(['received' => true]);

        $event = $this->repo->events('dev-myhasil')[0];
        $this->assertSame('valid', $event['signature_status']);
        $this->assertSame('unchecked', $event['hash_status']);
        $this->assertNull($event['hash']);
        $this->assertSame('evt-1', $event['event_id']);
        $this->assertSame('evt-1', $event['headers']['x-event-id']);
        $this->assertStringStartsWith('t=', $event['headers']['x-signature']);
        $this->assertSame($raw, $event['raw_body']);
    }

    public function test_tampered_signed_webhook_is_recorded_as_invalid(): void
    {
        $this->createInbox();
        $raw = '{"referenceId":"REF-1","data":[{"Status":"success"}]}';
        $signature = WebhookSignatureVerifier::signWebhook('client-secret', $raw, time());

        $this->postSigned(str_replace('success', 'fail', $raw), $signature)->assertOk();

        $this->assertSame('invalid', $this->repo->events('dev-myhasil')[0]['signature_status']);
    }

    public function test_old_signed_webhook_is_recorded_as_stale(): void
    {
        $this->createInbox();
        $raw = '{"referenceId":"REF-1"}';

        $this->postSigned($raw, WebhookSignatureVerifier::signWebhook('client-secret', $raw, time() - 600))->assertOk();

        $this->assertSame('stale', $this->repo->events('dev-myhasil')[0]['signature_status']);
    }

    public function test_unsigned_webhook_is_unchecked(): void
    {
        $this->createInbox();

        $this->postSigned('{"referenceId":"REF-1"}', null, null)->assertOk();

        $event = $this->repo->events('dev-myhasil')[0];
        $this->assertSame('unchecked', $event['signature_status']);
        $this->assertNull($event['event_id']);
    }
}
