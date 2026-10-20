<?php

namespace Tests\Unit;

use App\Services\SsmGatewayInvolvementRepository;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SsmGatewayInvolvementRepositoryTest extends TestCase
{
    private string $basePath;

    private SsmGatewayInvolvementRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir().'/ssm-gateway-inv-'.uniqid();
        $this->writeFixture('900101015555', 'AHMAD BIN ALI', ['director-plain-en']);
        $this->repo = new SsmGatewayInvolvementRepository($this->basePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);
        parent::tearDown();
    }

    private function writeFixture(string $idValue, string $name, array $identities): void
    {
        mkdir($this->basePath.'/'.$idValue, 0777, true);
        file_put_contents($this->basePath.'/'.$idValue.'/meta.json', json_encode([
            'idType' => 'MK', 'idValue' => $idValue, 'name' => $name,
        ]));
        foreach ($identities as $identity) {
            file_put_contents($this->basePath.'/'.$idValue.'/'.$identity.'.pdf', '%PDF-1.4 '.$idValue.' '.$identity);
        }
    }

    private function setDefault(string $json): void
    {
        file_put_contents($this->basePath.'/default.json', $json);
    }

    public function test_find_known_id_value(): void
    {
        $inv = $this->repo->find('900101015555');

        $this->assertSame('900101015555', $inv['key']);
        $this->assertSame('AHMAD BIN ALI', $inv['meta']['name']);
        $this->assertStringEndsWith('/900101015555/director-plain-en.pdf', $this->repo->reportFile($inv, 'director-plain-en'));
    }

    public function test_report_file_is_exact_variant_only(): void
    {
        $inv = $this->repo->find('900101015555');
        file_put_contents($this->basePath.'/900101015555/report.pdf', '%PDF-1.4 legacy');

        $this->assertNull($this->repo->reportFile($inv, 'director-plain-my'));
        $this->assertNull($this->repo->reportFile($inv, 'director-ctc-en'));
        $this->assertNull($this->repo->reportFile($inv, 'report'));
        $this->assertNull($this->repo->reportFile($inv, '../900101015555/director-plain-en'));
    }

    public function test_find_unknown_or_unsafe_id_returns_null(): void
    {
        $this->assertNull($this->repo->find('000000000000'));
        $this->assertNull($this->repo->find('../etc'));
        $this->assertNull($this->repo->find(''));
    }

    public function test_find_rejects_invalid_metadata(): void
    {
        mkdir($this->basePath.'/111111111111');
        file_put_contents($this->basePath.'/111111111111/meta.json', '{not json');
        $this->writeFixture('222222222222', 'X', []);
        file_put_contents($this->basePath.'/222222222222/meta.json', json_encode(['idType' => 'MK', 'idValue' => '999', 'name' => 'X']));
        $this->writeFixture('333333333333', 'X', []);
        file_put_contents($this->basePath.'/333333333333/meta.json', json_encode(['idType' => 'MK', 'idValue' => '333333333333']));

        $this->assertNull($this->repo->find('111111111111'));
        $this->assertNull($this->repo->find('222222222222'));
        $this->assertNull($this->repo->find('333333333333'));
    }

    public function test_find_rejects_symlinked_fixture_directory(): void
    {
        symlink($this->basePath.'/900101015555', $this->basePath.'/444444444444');

        $this->assertNull($this->repo->find('444444444444'));
        $this->assertNull($this->repo->resolve('444444444444'));
    }

    public function test_missing_base_path_is_empty(): void
    {
        $repo = new SsmGatewayInvolvementRepository($this->basePath.'/nope');

        $this->assertNull($repo->find('900101015555'));
        $this->assertNull($repo->resolve('900101015555'));
    }

    public function test_resolve_prefers_own_folder(): void
    {
        $this->writeFixture('000000000001', 'OWN PERSON', ['director-ctc-en']);
        $this->setDefault(json_encode(['idValue' => '900101015555']));

        $this->assertSame('000000000001', $this->repo->resolve('000000000001')['key']);
    }

    public function test_resolve_falls_back_to_default_for_unknown_id(): void
    {
        $this->setDefault(json_encode(['idValue' => '900101015555']));

        $resolved = $this->repo->resolve('000000000002');

        $this->assertSame('900101015555', $resolved['key']);
        $this->assertSame('AHMAD BIN ALI', $resolved['meta']['name']);
    }

    public function test_resolve_does_not_fall_back_from_incomplete_own_folder(): void
    {
        $this->writeFixture('000000000003', 'OWN PERSON', ['secretary-plain-my']);
        $this->setDefault(json_encode(['idValue' => '900101015555']));

        $own = $this->repo->resolve('000000000003');

        $this->assertSame('000000000003', $own['key']);
        $this->assertNull($this->repo->reportFile($own, 'director-plain-en'));
    }

    public function test_resolve_does_not_fall_back_from_corrupt_own_folder(): void
    {
        mkdir($this->basePath.'/000000000004');
        file_put_contents($this->basePath.'/000000000004/meta.json', '{broken');
        $this->setDefault(json_encode(['idValue' => '900101015555']));

        $this->assertNull($this->repo->resolve('000000000004'));
    }

    public function test_resolve_without_usable_default_is_null(): void
    {
        $this->assertNull($this->repo->resolve('000000000005'));

        $this->setDefault('{broken');
        $this->assertNull($this->repo->resolve('000000000005'));

        $this->setDefault(json_encode(['idValue' => 900101015555]));
        $this->assertNull($this->repo->resolve('000000000005'));

        $this->setDefault(json_encode(['idValue' => '../900101015555']));
        $this->assertNull($this->repo->resolve('000000000005'));

        $this->setDefault(json_encode(['idValue' => '777777777777']));
        $this->assertNull($this->repo->resolve('000000000005'));
    }

    public function test_resolve_rejects_unsafe_submitted_id(): void
    {
        $this->setDefault(json_encode(['idValue' => '900101015555']));

        $this->assertNull($this->repo->resolve('../etc'));
        $this->assertNull($this->repo->resolve(''));
    }

    public function test_is_safe_id(): void
    {
        $this->assertTrue(SsmGatewayInvolvementRepository::isSafeId('000123456789'));
        $this->assertTrue(SsmGatewayInvolvementRepository::isSafeId('A1_b-2'));
        $this->assertFalse(SsmGatewayInvolvementRepository::isSafeId(''));
        $this->assertFalse(SsmGatewayInvolvementRepository::isSafeId('a/b'));
        $this->assertFalse(SsmGatewayInvolvementRepository::isSafeId("abc\n"));
    }

    public function test_selection_ref_round_trip(): void
    {
        $this->assertSame('PI-900101015555', SsmGatewayInvolvementRepository::selectionRefNo('900101015555'));
        $this->assertSame('900101015555', SsmGatewayInvolvementRepository::idValueFromRef('PI-900101015555'));
        $this->assertNull(SsmGatewayInvolvementRepository::idValueFromRef('900101015555'));
    }

    public function test_report_url_identifies_source_and_variant(): void
    {
        $this->assertStringEndsWith(
            '/ssm-gateway/involvement-reports/900101015555/director-plain-en.pdf',
            $this->repo->reportUrl($this->repo->find('900101015555'), 'director-plain-en'),
        );
    }
}
