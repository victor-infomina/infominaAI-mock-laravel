<?php

namespace Tests\Unit;

use App\Services\WebhookInbox\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

class WebhookSignatureVerifierTest extends TestCase
{
    private const SECRET = 'secret-key-value';

    private const T = 1793318400;

    /** Produced by infominaAI-BE signWebhookBody('secret-key-value', BODY, 1793318400). */
    private const BODY = '{"referenceId":"R","name":"ALPHA — ü"}';

    private const BE_WEBHOOK_HEADER = 't=1793318400,v1=80da1f5285954e03470343c33592e87de6786809b7f32d7dfb5b3ceded4b7668';

    /** Produced by infominaAI-BE signCallbackParams('secret-key-value', ..., 1793318400). */
    private const BE_CALLBACK = [
        'referenceId' => 'R',
        'hash' => 'H',
        'status' => 'success',
        'remark' => '',
        'reportUrl' => '"https://s3/r.pdf?a=1&b=2"',
        'ts' => '1793318400',
        'signature' => '8ae2c886b025b320336325a191998f29396290668d2a684b7e042dfcd02b3668',
    ];

    public function test_sign_webhook_matches_the_be_signer_byte_for_byte(): void
    {
        $this->assertSame(self::BE_WEBHOOK_HEADER, WebhookSignatureVerifier::signWebhook(self::SECRET, self::BODY, self::T));
    }

    public function test_webhook_status_is_valid_for_the_be_signature(): void
    {
        $this->assertSame('valid', WebhookSignatureVerifier::webhookStatus(self::SECRET, self::BE_WEBHOOK_HEADER, self::BODY, self::T + 10));
    }

    public function test_webhook_status_is_invalid_when_the_body_changes(): void
    {
        $tampered = str_replace('"R"', '"R2"', self::BODY);

        $this->assertSame('invalid', WebhookSignatureVerifier::webhookStatus(self::SECRET, self::BE_WEBHOOK_HEADER, $tampered, self::T));
    }

    public function test_webhook_status_is_invalid_for_the_wrong_secret_or_malformed_header(): void
    {
        $this->assertSame('invalid', WebhookSignatureVerifier::webhookStatus('other', self::BE_WEBHOOK_HEADER, self::BODY, self::T));
        $this->assertSame('invalid', WebhookSignatureVerifier::webhookStatus(self::SECRET, 'garbage', self::BODY, self::T));
        $this->assertSame('invalid', WebhookSignatureVerifier::webhookStatus(self::SECRET, 't=abc,v1=00', self::BODY, self::T));
    }

    public function test_webhook_status_is_stale_beyond_five_minutes(): void
    {
        $this->assertSame('valid', WebhookSignatureVerifier::webhookStatus(self::SECRET, self::BE_WEBHOOK_HEADER, self::BODY, self::T + 300));
        $this->assertSame('stale', WebhookSignatureVerifier::webhookStatus(self::SECRET, self::BE_WEBHOOK_HEADER, self::BODY, self::T + 301));
    }

    public function test_webhook_status_is_unchecked_without_secret_or_header(): void
    {
        $this->assertSame('unchecked', WebhookSignatureVerifier::webhookStatus(null, self::BE_WEBHOOK_HEADER, self::BODY));
        $this->assertSame('unchecked', WebhookSignatureVerifier::webhookStatus(self::SECRET, null, self::BODY));
    }

    public function test_callback_canonical_sorts_by_name_and_skips_signature(): void
    {
        $this->assertSame(
            'hash=H&referenceId=R&remark=&reportUrl="https://s3/r.pdf?a=1&b=2"&status=success&ts=1793318400',
            WebhookSignatureVerifier::canonicalCallback(self::BE_CALLBACK),
        );
    }

    public function test_callback_status_is_valid_for_the_be_signature(): void
    {
        $this->assertSame('valid', WebhookSignatureVerifier::callbackStatus(self::SECRET, self::BE_CALLBACK, self::T + 10));
    }

    public function test_callback_status_is_invalid_when_a_param_changes(): void
    {
        $tampered = ['status' => 'fail'] + self::BE_CALLBACK;

        $this->assertSame('invalid', WebhookSignatureVerifier::callbackStatus(self::SECRET, $tampered, self::T));
    }

    public function test_callback_status_is_stale_beyond_five_minutes(): void
    {
        $this->assertSame('stale', WebhookSignatureVerifier::callbackStatus(self::SECRET, self::BE_CALLBACK, self::T + 301));
    }

    public function test_callback_status_is_unchecked_for_a_legacy_unsigned_callback(): void
    {
        $legacy = self::BE_CALLBACK;
        unset($legacy['ts'], $legacy['signature']);

        $this->assertSame('unchecked', WebhookSignatureVerifier::callbackStatus(self::SECRET, $legacy));
    }
}
