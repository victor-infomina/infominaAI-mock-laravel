<?php

namespace Tests\Feature;

use Tests\TestCase;

class EndToEndRoundTripTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ssm_mock.api_key' => 'test-key',
            'ssm_mock.api_secret' => 'test-secret',
        ]);
    }

    private function authHeaders(): array
    {
        return [
            'x-Gateway-APIKey' => 'test-key',
            'x-Gateway-APISecret' => 'test-secret',
        ];
    }

    public function test_full_profile_to_report_round_trip_for_the_example_case(): void
    {
        $search = $this->withHeaders($this->authHeaders())
            ->postJson('/get-search-entity', ['regNo' => '000000-X', 'entityType' => 'company']);
        $search->assertOk();
        $search->assertJsonPath('getSearchEntity.searchEntity.0.companyName', 'Example Company Sdn Bhd');

        $profile = $this->withHeaders($this->authHeaders())
            ->postJson('/v2/get-company-profile-document', ['regNo' => '000000-X']);
        $profile->assertOk();
        $requestRefNo = $profile->json('getCompProfile.requestRefNo');
        $this->assertSame('example-co', $requestRefNo);

        $order = $this->withHeaders($this->authHeaders())
            ->postJson('/get-order-document', ['requestRefNo' => $requestRefNo]);
        $order->assertOk();
        $order->assertJsonPath('getOrderDocument.data.status', 'Completed');

        $documentUrl = $order->json('getOrderDocument.data.documentUrl');
        $path = parse_url($documentUrl, PHP_URL_PATH);

        $report = $this->get($path);
        $report->assertOk();
        $report->assertHeader('Content-Type', 'application/pdf');
    }
}
