<?php

namespace App\Services;

class SsmCaseRepository
{
    public function __construct(private readonly string $basePath)
    {
    }

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

    public function searchByName(string $name, string $entityType): array
    {
        $needle = strtolower($name);

        return array_values(array_filter($this->all(), function (array $case) use ($needle, $entityType) {
            $companyName = strtolower($case['meta']['companyName'] ?? '');

            return str_contains($companyName, $needle)
                && strcasecmp($case['meta']['entityType'] ?? '', $entityType) === 0;
        }));
    }
}
