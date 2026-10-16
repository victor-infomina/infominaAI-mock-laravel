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

    public function test_find_by_reg_no_accepts_ssm_search_vocabulary_for_llp(): void
    {
        $this->makeCase('abc-plt', ['regNo' => '202204001923', 'companyName' => 'ABC PLT', 'entityType' => 'llp']);

        $repo = new SsmCaseRepository($this->fixturesPath);

        $this->assertNotNull($repo->findByRegNo('202204001923', 'limited_liability_partnerships'));
        $this->assertNotNull($repo->findByRegNo('202204001923', 'LIMITED_LIABILITY_PARTNERSHIPS'));
        $this->assertNotNull($repo->findByRegNo('202204001923', 'llp'));
        $this->assertNull($repo->findByRegNo('202204001923', 'company'));
    }

    public function test_search_by_name_accepts_ssm_search_vocabulary_for_llp(): void
    {
        $this->makeCase('abc-plt', ['regNo' => '202204001923', 'companyName' => 'ABC PLT', 'entityType' => 'llp']);
        $this->makeCase('abc-co', ['regNo' => 'ABC123', 'companyName' => 'ABC Sdn Bhd', 'entityType' => 'company']);

        $repo = new SsmCaseRepository($this->fixturesPath);
        $matches = $repo->searchByName('abc', 'limited_liability_partnerships');

        $this->assertCount(1, $matches);
        $this->assertSame('abc-plt', $matches[0]['key']);
    }

    public function test_all_ignores_entries_without_meta_json(): void
    {
        mkdir($this->fixturesPath.'/not-a-case', 0777, true);
        $this->makeCase('acme-co', ['regNo' => 'ABC123', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);

        $repo = new SsmCaseRepository($this->fixturesPath);

        $this->assertCount(1, $repo->all());
    }

    public function test_find_by_reg_no_any_matches_regardless_of_entity_type(): void
    {
        $this->makeCase('acme-co', ['regNo' => 'ABC123', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);

        $repo = new SsmCaseRepository($this->fixturesPath);
        $case = $repo->findByRegNoAny('abc123');

        $this->assertNotNull($case);
        $this->assertSame('acme-co', $case['key']);
    }

    public function test_find_by_reg_no_any_returns_null_when_no_case_matches(): void
    {
        $repo = new SsmCaseRepository($this->fixturesPath);

        $this->assertNull($repo->findByRegNoAny('unknown'));
    }

    public function test_idaman_documents_returns_null_when_no_idaman_folder(): void
    {
        $this->makeCase('acme-co', ['regNo' => 'ABC123', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);
        $repo = new SsmCaseRepository($this->fixturesPath);

        $this->assertNull($repo->idamanDocuments($repo->find('acme-co')));
    }

    public function test_idaman_documents_returns_decoded_list_json(): void
    {
        $this->makeCase('acme-co', ['regNo' => 'ABC123', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);
        $idamanDir = $this->fixturesPath.'/acme-co/idaman';
        mkdir($idamanDir, 0777, true);
        file_put_contents($idamanDir.'/list.json', json_encode([
            ['verId' => 'V1', 'formType' => '557', 'dateFiler' => '2024-01-01'],
        ]));

        $repo = new SsmCaseRepository($this->fixturesPath);
        $documents = $repo->idamanDocuments($repo->find('acme-co'));

        $this->assertCount(1, $documents);
        $this->assertSame('V1', $documents[0]['verId']);
    }

    public function test_idaman_documents_derives_document_date_for_hand_crafted_lists_that_only_have_date_filer(): void
    {
        $this->makeCase('acme-co', ['regNo' => 'ABC123', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);
        $idamanDir = $this->fixturesPath.'/acme-co/idaman';
        mkdir($idamanDir, 0777, true);
        file_put_contents($idamanDir.'/list.json', json_encode([
            ['verId' => 'V1', 'formType' => '557', 'dateFiler' => '2024-01-01'],
            ['verId' => 'V2', 'formType' => '49', 'dateFiler' => '2023-05-01T00:00:00+08:00', 'documentDate' => '20230501'],
        ]));

        $repo = new SsmCaseRepository($this->fixturesPath);
        $documents = $repo->idamanDocuments($repo->find('acme-co'));

        $this->assertSame('20240101', $documents[0]['documentDate']);
        $this->assertSame('2024-01-01T00:00:00+08:00', $documents[0]['dateFiler']);
        $this->assertSame('20230501', $documents[1]['documentDate']);
        $this->assertSame('2023-05-01T00:00:00+08:00', $documents[1]['dateFiler']);
    }

    public function test_idaman_document_file_returns_null_when_not_found(): void
    {
        $this->makeCase('acme-co', ['regNo' => 'ABC123', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);
        $repo = new SsmCaseRepository($this->fixturesPath);

        $this->assertNull($repo->idamanDocumentFile($repo->find('acme-co'), 'V1'));
    }

    public function test_idaman_document_file_returns_path_when_present(): void
    {
        $this->makeCase('acme-co', ['regNo' => 'ABC123', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);
        $idamanDir = $this->fixturesPath.'/acme-co/idaman';
        mkdir($idamanDir, 0777, true);
        file_put_contents($idamanDir.'/V1.tiff', 'binary-content');

        $repo = new SsmCaseRepository($this->fixturesPath);

        $this->assertSame($idamanDir.'/V1.tiff', $repo->idamanDocumentFile($repo->find('acme-co'), 'V1'));
    }

    public function test_idaman_document_file_rejects_unsafe_ver_id(): void
    {
        $this->makeCase('acme-co', ['regNo' => 'ABC123', 'companyName' => 'Acme Sdn Bhd', 'entityType' => 'company']);
        $repo = new SsmCaseRepository($this->fixturesPath);

        $this->assertNull($repo->idamanDocumentFile($repo->find('acme-co'), '../../etc/passwd'));
    }
}
