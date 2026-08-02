<?php

namespace Tests\Unit;

use App\Services\DnbCaseRepository;
use PHPUnit\Framework\TestCase;

class DnbCaseRepositoryTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesPath = sys_get_temp_dir().'/dnb-case-repo-test-'.uniqid();
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

    public function test_find_by_reg_no_matches_case_insensitively(): void
    {
        $this->makeCase('acme-sg', ['country' => 'singapore', 'regNo' => '201912345A', 'companyName' => 'Acme Pte Ltd']);

        $repo = new DnbCaseRepository($this->fixturesPath);

        $this->assertNotNull($repo->findByRegNo('singapore', '201912345a'));
        $this->assertNull($repo->findByRegNo('indonesia', '201912345A'));
    }

    public function test_find_by_reg_no_returns_null_when_no_match(): void
    {
        $repo = new DnbCaseRepository($this->fixturesPath);

        $this->assertNull($repo->findByRegNo('singapore', 'unknown'));
    }

    public function test_find_by_company_id_matches_case_insensitively(): void
    {
        $this->makeCase('acme-id', ['country' => 'indonesia', 'companyId' => 'ID12345', 'companyName' => 'Acme Indonesia']);

        $repo = new DnbCaseRepository($this->fixturesPath);

        $this->assertNotNull($repo->findByCompanyId('indonesia', 'id12345'));
        $this->assertNull($repo->findByCompanyId('singapore', 'ID12345'));
    }

    public function test_search_by_name_matches_substring_within_country(): void
    {
        $this->makeCase('acme-sg', ['country' => 'singapore', 'regNo' => '201912345A', 'companyName' => 'Acme Pte Ltd']);
        $this->makeCase('acme-id', ['country' => 'indonesia', 'companyId' => 'ID12345', 'companyName' => 'Acme Indonesia']);

        $repo = new DnbCaseRepository($this->fixturesPath);
        $matches = $repo->searchByName('singapore', 'acme');

        $this->assertCount(1, $matches);
        $this->assertSame('acme-sg', $matches[0]['key']);
    }

    public function test_find_by_reg_no_does_not_match_a_partial_substring(): void
    {
        $this->makeCase('acme-sg', ['country' => 'singapore', 'regNo' => '201912345A', 'companyName' => 'Acme Pte Ltd']);

        $repo = new DnbCaseRepository($this->fixturesPath);

        $this->assertNull($repo->findByRegNo('singapore', '201912345'));
    }

    public function test_find_by_company_id_does_not_match_a_partial_substring(): void
    {
        $this->makeCase('acme-id', ['country' => 'indonesia', 'companyId' => 'ID12345', 'companyName' => 'Acme Indonesia']);

        $repo = new DnbCaseRepository($this->fixturesPath);

        $this->assertNull($repo->findByCompanyId('indonesia', 'ID123'));
    }
}
