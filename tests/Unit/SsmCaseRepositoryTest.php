<?php

namespace Tests\Unit;

use App\Services\SsmCaseRepository;
use PHPUnit\Framework\TestCase;

class SsmCaseRepositoryTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesPath = sys_get_temp_dir().'/ssm-case-repo-test-'.uniqid();
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

    public function test_find_returns_null_for_unknown_case(): void
    {
        $repo = new SsmCaseRepository($this->fixturesPath);

        $this->assertNull($repo->find('does-not-exist'));
    }

    public function test_find_returns_case_with_meta(): void
    {
        $this->makeCase('acme-co', ['regNo' => '123456', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);

        $repo = new SsmCaseRepository($this->fixturesPath);
        $case = $repo->find('acme-co');

        $this->assertSame('acme-co', $case['key']);
        $this->assertSame('123456', $case['meta']['regNo']);
        $this->assertSame($this->fixturesPath.'/acme-co', $case['path']);
    }

    public function test_find_by_reg_no_matches_case_insensitively_and_by_entity_type(): void
    {
        $this->makeCase('acme-co', ['regNo' => 'ABC123', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);

        $repo = new SsmCaseRepository($this->fixturesPath);

        $this->assertNotNull($repo->findByRegNo('abc123', 'company'));
        $this->assertNull($repo->findByRegNo('abc123', 'business'));
        $this->assertNull($repo->findByRegNo('other', 'company'));
    }

    public function test_search_by_name_matches_substring_case_insensitively(): void
    {
        $this->makeCase('acme-co', ['regNo' => 'ABC123', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);
        $this->makeCase('beta-co', ['regNo' => 'XYZ999', 'companyName' => 'Beta Holdings', 'entityType' => 'company']);

        $repo = new SsmCaseRepository($this->fixturesPath);
        $matches = $repo->searchByName('acme', 'company');

        $this->assertCount(1, $matches);
        $this->assertSame('acme-co', $matches[0]['key']);
    }

    public function test_all_ignores_entries_without_meta_json(): void
    {
        mkdir($this->fixturesPath.'/not-a-case', 0777, true);
        $this->makeCase('acme-co', ['regNo' => 'ABC123', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);

        $repo = new SsmCaseRepository($this->fixturesPath);

        $this->assertCount(1, $repo->all());
    }
}
