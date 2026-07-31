<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApiClients;
use Tests\TestCase;

class EndToEndRoundTripTest extends TestCase
{
    use RefreshDatabase, InteractsWithApiClients;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpApiClient();
    }

    public function test_full_profile_to_report_round_trip_for_the_example_case(): void
    {
        $search = $this->withHeaders($this->authHeaders())
            ->postJson('/get-search-entity', ['regNo' => '000000-X', 'entityType' => 'company']);
        $search->assertOk();
        $search->assertJsonPath('getSearchEntity.searchEntity.data.0.companyName', 'Example Company Sdn Bhd');

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

    public function test_idaman_list_to_document_round_trip_for_the_example_case(): void
    {
        $list = $this->withHeaders($this->authHeaders())
            ->postJson('/get-image-list', ['regNo' => '000000-X']);
        $list->assertOk();
        $list->assertJsonPath('getImageView.documentInfos.documentInfos.0.verId', 'EX-V1');

        $document = $this->withHeaders($this->authHeaders())
            ->postJson('/get-image', ['regNo' => '000000-X', 'verId' => 'EX-V1']);
        $document->assertOk();
        $this->assertNotEmpty($document->json('getImage.docContent'));
    }
}
