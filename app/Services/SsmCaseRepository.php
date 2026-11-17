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
        $entityType = self::normalizeEntityType($entityType);

        foreach ($this->all() as $case) {
            if (
                strcasecmp($case['meta']['regNo'] ?? '', $regNo) === 0
                && self::normalizeEntityType($case['meta']['entityType'] ?? '') === $entityType
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

    /**
     * CIDP get-status-entity lookup: `regNo` may be the case's meta.json number or the
     * old-format number from its profile, and `entityType` (meta.json vocabulary, or null
     * for any) only narrows the match. meta.json is the identity of record even when the
     * saved profile belongs to another entity, so meta numbers are tried first and
     * profiles are only opened when no meta number matches.
     */
    public function findForStatus(string $regNo, ?string $entityType): ?array
    {
        $regNo = trim($regNo);
        $candidates = array_values(array_filter(
            $this->all(),
            fn (array $case) => $entityType === null || ($case['meta']['entityType'] ?? '') === $entityType,
        ));

        foreach ($candidates as $case) {
            if (strcasecmp(trim((string) ($case['meta']['regNo'] ?? '')), $regNo) === 0) {
                return $case;
            }
        }

        foreach ($candidates as $case) {
            $oldRegNo = SsmEntityStatus::oldRegNo((string) ($case['meta']['entityType'] ?? ''), $this->profile($case));

            if ($oldRegNo !== '' && strcasecmp($oldRegNo, $regNo) === 0) {
                return $case;
            }
        }

        return null;
    }

    /** The case's decoded profile JSON for its entity type, or null if absent/unreadable. */
    public function profile(array $case): ?array
    {
        $file = match ($case['meta']['entityType'] ?? null) {
            'company' => 'company-profile.json',
            'business' => 'business-profile.json',
            'llp' => 'llp-profile.json',
            default => null,
        };

        if ($file === null || ! is_file($case['path'].'/'.$file)) {
            return null;
        }

        $profile = json_decode(file_get_contents($case['path'].'/'.$file), true);

        return is_array($profile) ? $profile : null;
    }

    public function searchByName(string $name, string $entityType): array
    {
        $needle = strtolower($name);
        $entityType = self::normalizeEntityType($entityType);

        return array_values(array_filter($this->all(), function (array $case) use ($needle, $entityType) {
            $companyName = strtolower($case['meta']['companyName'] ?? '');

            return str_contains($companyName, $needle)
                && self::normalizeEntityType($case['meta']['entityType'] ?? '') === $entityType;
        }));
    }

    /**
     * SSM's get-search-entity endpoint has its own entityType vocabulary: company and
     * business are spelled the same as in meta.json, but LLP arrives as
     * `limited_liability_partnerships` (infominaAI-BE's toSsmSearchEntityType), so both
     * spellings must resolve to the `llp` stored in meta.json.
     */
    private static function normalizeEntityType(string $entityType): string
    {
        $entityType = strtolower($entityType);

        return $entityType === 'limited_liability_partnerships' ? 'llp' : $entityType;
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
