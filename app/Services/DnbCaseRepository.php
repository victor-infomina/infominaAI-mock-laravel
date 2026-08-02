<?php

namespace App\Services;

class DnbCaseRepository
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

            $metaPath = $this->basePath.'/'.$entry.'/meta.json';

            if (! is_file($metaPath)) {
                continue;
            }

            $meta = json_decode(file_get_contents($metaPath), true);

            if (! is_array($meta)) {
                continue;
            }

            $cases[] = ['key' => $entry, 'meta' => $meta, 'path' => $this->basePath.'/'.$entry];
        }

        return $cases;
    }

    public function findByRegNo(string $country, string $regNo): ?array
    {
        foreach ($this->all() as $case) {
            if (
                strcasecmp($case['meta']['country'] ?? '', $country) === 0
                && strcasecmp($case['meta']['regNo'] ?? '', $regNo) === 0
            ) {
                return $case;
            }
        }

        return null;
    }

    public function findByCompanyId(string $country, string $companyId): ?array
    {
        foreach ($this->all() as $case) {
            if (
                strcasecmp($case['meta']['country'] ?? '', $country) === 0
                && strcasecmp($case['meta']['companyId'] ?? '', $companyId) === 0
            ) {
                return $case;
            }
        }

        return null;
    }

    public function searchByName(string $country, string $name): array
    {
        $needle = strtolower($name);

        return array_values(array_filter($this->all(), function (array $case) use ($country, $needle) {
            if (strcasecmp($case['meta']['country'] ?? '', $country) !== 0) {
                return false;
            }

            return str_contains(strtolower($case['meta']['companyName'] ?? ''), $needle);
        }));
    }
}
