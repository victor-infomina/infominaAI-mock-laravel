<?php

namespace Tests\Concerns;

use App\Models\ApiClient;
use Illuminate\Support\Facades\Cache;

trait InteractsWithSsmGatewayToken
{
    protected ApiClient $ssmGatewayClient;

    protected string $ssmGatewayToken = 'test-ssm-gateway-token';

    protected function setUpSsmGatewayToken(): void
    {
        [$this->ssmGatewayClient] = ApiClient::createWithSecret('ssm-gateway-test', null, 'ssm_gateway');
        Cache::put('ssm_gateway_token:'.$this->ssmGatewayToken, $this->ssmGatewayClient->id, 3600);
    }

    protected function bearerHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->ssmGatewayToken];
    }
}
