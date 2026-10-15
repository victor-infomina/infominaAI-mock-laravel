<?php

namespace Tests\Unit;

use App\Services\SsmGatewayInvolvementRepository;
use Tests\TestCase;

class SsmGatewayInvolvementRepositoryTest extends TestCase
{
    private string $basePath;

    private SsmGatewayInvolvementRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir().'/ssm-gateway-inv-'.uniqid();
        mkdir($this->basePath.'/900101015555', 0777, true);
        file_put_contents($this->basePath.'/900101015555/meta.json', json_encode([
            'idType' => 'MK', 'idValue' => '900101015555', 'name' => 'AHMAD BIN ALI',
        ]));
        file_put_contents($this->basePath.'/900101015555/report.pdf', '%PDF-1.4 fake');
        $this->repo = new SsmGatewayInvolvementRepository($this->basePath);
    }

    public function test_find_known_id_value(): void
    {
        $inv = $this->repo->find('900101015555');

        $this->assertSame('900101015555', $inv['key']);
        $this->assertSame('AHMAD BIN ALI', $inv['meta']['name']);
        $this->assertStringEndsWith('/900101015555/report.pdf', $this->repo->reportFile($inv));
    }

    public function test_find_unknown_or_unsafe_id_returns_null(): void
    {
        $this->assertNull($this->repo->find('000000000000'));
        $this->assertNull($this->repo->find('../etc'));
        $this->assertNull($this->repo->find(''));
    }

    public function test_missing_base_path_is_empty(): void
    {
        $this->assertNull((new SsmGatewayInvolvementRepository($this->basePath.'/nope'))->find('900101015555'));
    }

    public function test_selection_ref_round_trip(): void
    {
        $this->assertSame('PI-900101015555', SsmGatewayInvolvementRepository::selectionRefNo('900101015555'));
        $this->assertSame('900101015555', SsmGatewayInvolvementRepository::idValueFromRef('PI-900101015555'));
        $this->assertNull(SsmGatewayInvolvementRepository::idValueFromRef('900101015555'));
    }

    public function test_report_url_uses_named_route(): void
    {
        $this->assertStringEndsWith('/ssm-gateway/involvement-reports/900101015555.pdf', $this->repo->reportUrl($this->repo->find('900101015555')));
    }
}
