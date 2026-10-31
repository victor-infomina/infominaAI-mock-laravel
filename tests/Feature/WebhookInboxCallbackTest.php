<?php

namespace Tests\Feature;

use App\Services\WebhookInbox\WebhookHashVerifier;
use App\Services\WebhookInbox\WebhookInboxRepository;
use App\Services\WebhookInbox\WebhookSignatureVerifier;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WebhookInboxCallbackTest extends TestCase
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
        $this->token = $this->repo->create('dev-myhasil', ['identifier' => 'client-key', 'secret' => 'client-secret', 'response_mode' => 'ok'])['token'];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function url(?string $token = null): string
    {
        return '/webhook-inbox/dev-myhasil/'.($token ?? $this->token).'/callback';
    }

    public function test_unknown_inbox_returns_404(): void
    {
        $this->get('/webhook-inbox/nope/'.str_repeat('a', 32).'/callback?referenceId=R')->assertNotFound();
    }

    public function test_wrong_token_returns_404_and_stores_nothing(): void
    {
        $this->get($this->url(str_repeat('0', 32)).'?referenceId=R')->assertNotFound();
        $this->get('/webhook-inbox/dev-myhasil/callback?referenceId=R')->assertNotFound();

        $this->assertSame([], $this->repo->events('dev-myhasil'));
    }

    public function test_callback_redirect_is_recorded_and_rendered_with_hash_status(): void
    {
        $hash = WebhookHashVerifier::compute('client-key', 'client-secret', 'ORDER-9');

        $response = $this->get($this->url().'?'.http_build_query([
            'referenceId' => 'ORDER-9',
            'transactionId' => 'tx-9',
            'hash' => $hash,
            'status' => 'success',
            'remark' => '',
            'profileUrl' => '"https://s3/profile.json"',
            'reportUrl' => '',
        ]));

        $response->assertOk();
        $response->assertSee('ORDER-9');
        $response->assertSee('tx-9');
        $response->assertSee('valid');

        $event = $this->repo->events('dev-myhasil')[0];
        $this->assertSame('callback', $event['kind']);
        $this->assertSame('callback', $event['event_type']);
        $this->assertSame('ORDER-9', $event['reference_id']);
        $this->assertSame('valid', $event['hash_status']);
        $this->assertSame('success', $event['query']['status']);
        $this->assertNull($event['body']);
        $this->assertSame(200, $event['response_status']);
    }

    public function test_bad_hash_is_shown_as_invalid(): void
    {
        $this->get($this->url().'?referenceId=ORDER-9&hash=nope&status=success')
            ->assertOk()
            ->assertSee('invalid');
    }

    public function test_opening_the_landing_page_without_parameters_does_not_crash(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('No parameters received');

        $this->assertSame('unchecked', $this->repo->events('dev-myhasil')[0]['hash_status']);
    }

    public function test_callback_with_invalid_utf8_query_is_still_recorded(): void
    {
        $this->get($this->url().'?referenceId=ORDER-9&remark=%FF')->assertOk();

        $events = $this->repo->events('dev-myhasil');
        $this->assertCount(1, $events);
        $this->assertSame('ORDER-9', $events[0]['reference_id']);
    }

    /** @return array<string, string> */
    private function signedParams(int $ts): array
    {
        $params = [
            'referenceId' => 'ORDER-9',
            'hash' => WebhookHashVerifier::compute('client-key', 'client-secret', 'ORDER-9'),
            'status' => 'success',
            'remark' => '',
            'reportUrl' => '"https://s3.example.com/r.pdf?a=1&b=2"',
            'ts' => (string) $ts,
        ];
        $params['signature'] = hash_hmac('sha256', WebhookSignatureVerifier::canonicalCallback($params), 'client-secret');

        return $params;
    }

    public function test_signed_callback_is_recorded_and_rendered_with_a_valid_signature(): void
    {
        $response = $this->get($this->url().'?'.http_build_query($this->signedParams(time())));

        $response->assertOk()->assertSee('Signature');
        $event = $this->repo->events('dev-myhasil')[0];
        $this->assertSame('valid', $event['signature_status']);
        $this->assertSame('valid', $event['hash_status']);
    }

    public function test_signed_callback_with_an_edited_param_is_invalid(): void
    {
        $params = $this->signedParams(time());
        $params['status'] = 'fail';

        $this->get($this->url().'?'.http_build_query($params))->assertOk();

        $this->assertSame('invalid', $this->repo->events('dev-myhasil')[0]['signature_status']);
    }

    public function test_legacy_callback_signature_is_unchecked(): void
    {
        $this->get($this->url().'?referenceId=ORDER-9&status=success')->assertOk();

        $this->assertSame('unchecked', $this->repo->events('dev-myhasil')[0]['signature_status']);
    }
}
