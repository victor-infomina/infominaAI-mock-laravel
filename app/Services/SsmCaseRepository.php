<?php

namespace App\Services;

class SsmCaseRepository
{
    public function __construct(private readonly string $basePath) {}

    public function all(): array
    {
        $cases = [];

        if (! is_dir($this->basePath)) {
            return $cases;
        }

        foreach (scandir($this->basePath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $case = $this->find($entry);

            if ($case !== null) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    public function find(string $caseKey): ?array
    {
        $path = $this->basePath.'/'.$caseKey;
        $metaPath = $path.'/meta.json';

        if (! is_file($metaPath)) {
            return null;
        }

        $meta = json_decode(file_get_contents($metaPath), true);

        if (! is_array($meta)) {
            return null;
        }

        return ['key' => $caseKey, 'meta' => $meta, 'path' => $path];
    }

    public function findByRegNo(string $regNo, string $entityType): ?array
    {
        foreach ($this->all() as $case) {
            if (
                strcasecmp($case['meta']['regNo'] ?? '', $regNo) === 0
                && strcasecmp($case['meta']['entityType'] ?? '', $entityType) === 0
            ) {
                return $case;
            }
        }

        return null;
    }

    public function findByRegNoAny(string $regNo): ?array
    {
        foreach ($this->all() as $case) {
            if (strcasecmp($case['meta']['regNo'] ?? '', $regNo) === 0) {
                return $case;
            }
        }

        return null;
    }

    public function searchByName(string $name, string $entityType): array
    {
        $needle = strtolower($name);

        return array_values(array_filter($this->all(), function (array $case) use ($needle, $entityType) {
            $companyName = strtolower($case['meta']['companyName'] ?? '');

            return str_contains($companyName, $needle)
                && strcasecmp($case['meta']['entityType'] ?? '', $entityType) === 0;
        }));
    }

    public function idamanDocuments(array $case): ?array
    {
        $listPath = $case['path'].'/idaman/list.json';

        if (! is_file($listPath)) {
            return null;
        }

        $documents = json_decode(file_get_contents($listPath), true);

        if (! is_array($documents)) {
            return null;
        }

        return array_map([$this, 'withGatewayDateFields'], $documents);
    }

    /**
     * Hand-crafted lists may carry only dateFiler; the BE and FE read
     * documentDate (YYYYMMDD), so derive whichever of the two is missing.
     */
    private function withGatewayDateFields(array $entry): array
    {
        $source = $entry['documentDate'] ?? $entry['dateFiler'] ?? null;

        if (! is_string($source) || $source === '') {
            return $entry;
        }

        $entry['documentDate'] = IdamanDocumentDates::yyyymmdd($source);
        $entry['dateFiler'] = IdamanDocumentDates::iso($entry['dateFiler'] ?? $source);

        return $entry;
    }

    public function idamanDocumentFile(array $case, string $verId): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $verId)) {
            return null;
        }

        $matches = glob($case['path'].'/idaman/'.$verId.'.*');

        return $matches[0] ?? null;
    }
}
