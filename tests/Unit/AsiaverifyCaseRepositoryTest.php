<?php

namespace Tests\Unit;

use App\Services\AsiaverifyCaseRepository;
use PHPUnit\Framework\TestCase;

class AsiaverifyCaseRepositoryTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesPath = sys_get_temp_dir().'/asiaverify-case-repo-test-'.uniqid();
        mkdir($this->fixturesPath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->fixturesPath);
        parent::tearDown();
    }

    private function makeCase(string $key, array $meta): void
    {
        $path = $this->fixturesPath.'/'.$key;
        mkdir($path, 0777, true);
        file_put_contents($path.'/meta.json', json_encode($meta));
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function test_find_returns_null_for_unknown_company(): void
    {
        $repo = new AsiaverifyCaseRepository($this->fixturesPath);

        $this->assertNull($repo->find('VNM', '0101234567'));
    }

    public function test_find_matches_country_case_insensitively(): void
    {
        $this->makeCase('nguyen-trading', ['country' => 'VNM', 'companyId' => '0101234567', 'companyName' => 'Nguyen Trading Co']);

        $repo = new AsiaverifyCaseRepository($this->fixturesPath);

        $this->assertNotNull($repo->find('vnm', '0101234567'));
        $this->assertNull($repo->find('THA', '0101234567'));
    }

    public function test_find_requires_exact_company_id(): void
    {
        $this->makeCase('nguyen-trading', ['country' => 'VNM', 'companyId' => '0101234567', 'companyName' => 'Nguyen Trading Co']);

        $repo = new AsiaverifyCaseRepository($this->fixturesPath);

        $this->assertNull($repo->find('VNM', '010123456'));
    }

    public function test_search_matches_company_name_substring(): void
    {
        $this->makeCase('nguyen-trading', ['country' => 'VNM', 'companyId' => '0101234567', 'companyName' => 'Nguyen Trading Co']);
        $this->makeCase('other-co', ['country' => 'VNM', 'companyId' => '0109999999', 'companyName' => 'Other Company']);

        $repo = new AsiaverifyCaseRepository($this->fixturesPath);
        $matches = $repo->search('VNM', 'nguyen');

        $this->assertCount(1, $matches);
        $this->assertSame('nguyen-trading', $matches[0]['key']);
    }

    public function test_search_matches_company_id_substring(): void
    {
        $this->makeCase('nguyen-trading', ['country' => 'VNM', 'companyId' => '0101234567', 'companyName' => 'Nguyen Trading Co']);

        $repo = new AsiaverifyCaseRepository($this->fixturesPath);
        $matches = $repo->search('VNM', '01012');

        $this->assertCount(1, $matches);
    }

    public function test_search_excludes_other_countries(): void
    {
        $this->makeCase('nguyen-trading', ['country' => 'VNM', 'companyId' => '0101234567', 'companyName' => 'Nguyen Trading Co']);

        $repo = new AsiaverifyCaseRepository($this->fixturesPath);

        $this->assertCount(0, $repo->search('THA', 'nguyen'));
    }
}
