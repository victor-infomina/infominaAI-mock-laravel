<?php

namespace Tests\Concerns;

use App\Models\ApiClient;

trait InteractsWithApiClients
{
    private ApiClient $apiClient;

    private string $apiClientSecret;

    protected function setUpApiClient(): void
    {
        [$this->apiClient, $this->apiClientSecret] = ApiClient::createWithSecret('test-client', null);
    }

    private function authHeaders(): array
    {
        return [
            'x-Gateway-APIKey' => $this->apiClient->key,
            'x-Gateway-APISecret' => $this->apiClientSecret,
        ];
    }
}
