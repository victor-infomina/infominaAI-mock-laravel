<?php

namespace Tests\Feature;

use App\Services\SsmCaseRepository;
use Tests\TestCase;

class SsmCaseRepositoryBindingTest extends TestCase
{
    public function test_container_resolves_ssm_case_repository(): void
    {
        $repo = $this->app->make(SsmCaseRepository::class);

        $this->assertInstanceOf(SsmCaseRepository::class, $repo);
    }
}
