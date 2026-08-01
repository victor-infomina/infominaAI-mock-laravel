<?php

namespace App\Services;

class AsiaverifyCaseRepository
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

    public function find(string $country, string $companyId): ?array
    {
        foreach ($this->all() as $case) {
            if (
                strcasecmp($case['meta']['country'] ?? '', $country) === 0
                && ($case['meta']['companyId'] ?? null) === $companyId
            ) {
                return $case;
            }
        }

        return null;
    }

    public function search(string $country, string $keyword): array
    {
        $needle = strtolower($keyword);

        return array_values(array_filter($this->all(), function (array $case) use ($country, $needle) {
            if (strcasecmp($case['meta']['country'] ?? '', $country) !== 0) {
                return false;
            }

            $companyName = strtolower($case['meta']['companyName'] ?? '');
            $companyId = strtolower($case['meta']['companyId'] ?? '');

            return str_contains($companyName, $needle) || str_contains($companyId, $needle);
        }));
    }
}
