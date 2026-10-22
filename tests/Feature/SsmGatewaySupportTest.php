<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSsmGatewayToken;
use Tests\TestCase;

class SsmGatewaySupportTest extends TestCase
{
    use InteractsWithSsmGatewayToken;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSsmGatewayToken();
    }

    public function test_file_upload_returns_bare_array(): void
    {
        $response = $this->withHeaders($this->bearerHeaders())
            ->post('/ssm-gateway/file/upload', ['file' => UploadedFile::fake()->create('issue.pdf', 12, 'application/pdf')]);

        $response->assertOk();
        $json = $response->json();
        $this->assertIsList($json);
        $this->assertCount(1, $json);
        $this->assertSame('issue.pdf', $json[0]['ori_filename']);
        $this->assertSame('.pdf', $json[0]['ext']);
        $this->assertStringEndsWith('.pdf', $json[0]['filename']);
        $this->assertStringContainsString('/ssm-gateway/uploads/', $json[0]['url']);
    }

    public function test_file_upload_without_file_is_422(): void
    {
        $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/file/upload', [])
            ->assertStatus(422)->assertJsonPath('message', 'No file uploaded');
    }

    public function test_helpdesk_submit_returns_ticket(): void
    {
        $response = $this->withHeaders($this->bearerHeaders())
            ->postJson('/ssm-gateway/helpdesk/submit', [
                '_user_id' => 1, 'name' => 'SSM Mock', 'email' => 'a@b.c', 'phone' => '',
                'external_ticket_no' => 'ORDMOCKABC', 'subject' => 'Bad PDF', 'desc' => 'Blank page',
                'attachments' => json_encode([['url' => 'x', 'filename' => 'f.pdf', 'ori_filename' => 'f.pdf', 'ext' => '.pdf', 'size' => '1.0', 'type' => 'pdf']]),
            ]);

        $response->assertOk();
        $response->assertJsonPath('error', false);
        $response->assertJsonPath('code', 200);
        $response->assertJsonPath('results.subject', 'Bad PDF');
        $response->assertJsonPath('results.external_ticket_no', 'ORDMOCKABC');
        $response->assertJsonPath('results.status.label', 'Open');
        $this->assertStringStartsWith('HD-MOCK-', $response->json('results.internal_ticket_no'));
        $this->assertIsInt($response->json('results.created_at'));
    }

    public function test_helpdesk_ticket_numbers_increment(): void
    {
        $a = $this->withHeaders($this->bearerHeaders())->postJson('/ssm-gateway/helpdesk/submit', ['subject' => 'a', 'desc' => 'a'])->json('results.id');
        $b = $this->withHeaders($this->bearerHeaders())->postJson('/ssm-gateway/helpdesk/submit', ['subject' => 'b', 'desc' => 'b'])->json('results.id');
        $this->assertSame($a + 1, $b);
    }

    public function test_helpdesk_sequence_starts_at_one_on_database_cache(): void
    {
        config(['cache.default' => 'database']);
        Cache::put('ssm_gateway_token:'.$this->ssmGatewayToken, $this->ssmGatewayClient->id, 3600);

        $first = $this->withHeaders($this->bearerHeaders())->postJson('/ssm-gateway/helpdesk/submit', ['subject' => 'a', 'desc' => 'a']);
        $second = $this->withHeaders($this->bearerHeaders())->postJson('/ssm-gateway/helpdesk/submit', ['subject' => 'b', 'desc' => 'b']);

        $first->assertOk()->assertJsonPath('results.id', 1)->assertJsonPath('results.internal_ticket_no', 'HD-MOCK-1');
        $second->assertOk()->assertJsonPath('results.id', 2)->assertJsonPath('results.internal_ticket_no', 'HD-MOCK-2');
    }

    public function test_requires_bearer_token(): void
    {
        $this->postJson('/ssm-gateway/helpdesk/submit', [])->assertStatus(401);
        $this->post('/ssm-gateway/file/upload', [])->assertStatus(401);
    }
}
