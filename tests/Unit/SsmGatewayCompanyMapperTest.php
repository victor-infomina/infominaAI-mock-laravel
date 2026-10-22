<?php

namespace Tests\Unit;

use App\Services\SsmCaseRepository;
use App\Services\SsmGateway\CompanyMapper;
use Tests\TestCase;

class SsmGatewayCompanyMapperTest extends TestCase
{
    private string $basePath;

    private CompanyMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/ssm-gateway-mapper-'.uniqid();
        $this->makeCase('infomina-berhad', '200701018579', 'INFOMINA BERHAD', 'company');
        $this->makeCase('mina-trading', 'JM0123456-X', 'MINA TRADING', 'business');
        $this->makeCase('abc-plt', 'LLP0001234-LGN', 'ABC PLT', 'llp');

        $this->mapper = new CompanyMapper(new SsmCaseRepository($this->basePath));
    }

    private function makeCase(string $key, string $regNo, string $name, string $type): void
    {
        mkdir($this->basePath.'/'.$key, 0777, true);
        file_put_contents($this->basePath.'/'.$key.'/meta.json', json_encode([
            'regNo' => $regNo, 'companyName' => $name, 'entityType' => $type,
        ]));
    }

    public function test_id_is_stable_positive_crc32(): void
    {
        $this->assertSame((int) sprintf('%u', crc32('infomina-berhad')), $this->mapper->idFor('infomina-berhad'));
        $this->assertGreaterThan(0, $this->mapper->idFor('infomina-berhad'));
        $this->assertSame($this->mapper->idFor('infomina-berhad'), $this->mapper->idFor('infomina-berhad'));
    }

    public function test_find_by_id_round_trips(): void
    {
        $case = $this->mapper->findById($this->mapper->idFor('mina-trading'));

        $this->assertSame('mina-trading', $case['key']);
        $this->assertNull($this->mapper->findById(1));
    }

    public function test_search_result_shape(): void
    {
        $case = $this->mapper->findBySlug('infomina-berhad');

        $this->assertSame([
            'id' => $this->mapper->idFor('infomina-berhad'),
            'name' => 'INFOMINA BERHAD',
            'slug' => 'infomina-berhad',
            'reg_no' => '200701018579',
            'reg_no_old' => '200701018579',
            'entity' => 'Company',
            'entity_type_id' => 1,
        ], $this->mapper->toSearchResult($case));
    }

    public function test_info_shape_per_entity_type(): void
    {
        $company = $this->mapper->toInfo($this->mapper->findBySlug('infomina-berhad'));
        $this->assertSame(1, $company['is_roc']);
        $this->assertSame('Company', $company['entity_type']);
        $this->assertSame('ROC', $company['entity_type_code']);
        $this->assertSame('200701018579', $company['registration_no']);
        $this->assertSame('L', $company['origin']);

        $business = $this->mapper->toInfo($this->mapper->findBySlug('mina-trading'));
        $this->assertSame(0, $business['is_roc']);
        $this->assertSame('ROB', $business['entity_type_code']);

        $llp = $this->mapper->toInfo($this->mapper->findBySlug('abc-plt'));
        $this->assertSame('LLP', $llp['entity_type_code']);
        $this->assertSame('LLP', $llp['entity_type']);
    }

    public function test_find_by_reg_no_is_case_insensitive_and_trimmed(): void
    {
        $this->assertSame('mina-trading', $this->mapper->findByRegNo(' jm0123456-x ')['key']);
        $this->assertNull($this->mapper->findByRegNo('nope'));
    }

    public function test_report_url_points_at_existing_report_route(): void
    {
        $this->assertStringEndsWith('/reports/infomina-berhad.pdf', $this->mapper->reportUrl($this->mapper->findBySlug('infomina-berhad')));
    }
}
