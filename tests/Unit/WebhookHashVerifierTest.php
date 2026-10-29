<?php

namespace Tests\Unit;

use App\Services\WebhookInbox\WebhookHashVerifier;
use PHPUnit\Framework\TestCase;

class WebhookHashVerifierTest extends TestCase
{
    /** Same formula as infominaAI-BE ApiCredentialService.computeHash. */
    public function test_compute_matches_the_be_formula(): void
    {
        $expected = hash_hmac('sha256', 'client-key'.'client-secret'.'REF-1', 'client-secret');

        $this->assertSame($expected, WebhookHashVerifier::compute('client-key', 'client-secret', 'REF-1'));
    }

    public function test_status_is_valid_for_a_matching_hash(): void
    {
        $hash = WebhookHashVerifier::compute('k', 's', 'REF');

        $this->assertSame('valid', WebhookHashVerifier::status('k', 's', 'REF', $hash));
    }

    public function test_status_is_invalid_for_a_mismatching_hash(): void
    {
        $this->assertSame('invalid', WebhookHashVerifier::status('k', 's', 'REF', 'deadbeef'));
    }

    public function test_status_is_unchecked_when_any_input_is_missing(): void
    {
        $hash = WebhookHashVerifier::compute('k', 's', 'REF');

        $this->assertSame('unchecked', WebhookHashVerifier::status(null, 's', 'REF', $hash));
        $this->assertSame('unchecked', WebhookHashVerifier::status('k', null, 'REF', $hash));
        $this->assertSame('unchecked', WebhookHashVerifier::status('k', 's', null, $hash));
        $this->assertSame('unchecked', WebhookHashVerifier::status('k', 's', 'REF', null));
        $this->assertSame('unchecked', WebhookHashVerifier::status('k', 's', 'REF', ''));
    }
}
