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

    /**
     * get-search-entity and the profile endpoints: `regNo` may be the new or the old
     * number (see matchRegNo); `entityType` accepts SSM's own vocabulary too.
     */
    public function findByRegNo(string $regNo, string $entityType): ?array
    {
        $entityType = self::normalizeEntityType($entityType);

        return $this->matchRegNo(
            $regNo,
            fn (array $case) => self::normalizeEntityType((string) ($case['meta']['entityType'] ?? '')) === $entityType,
        );
    }

    /** get-image-list and get-image: new or old number, any entity type. */
    public function findByRegNoAny(string $regNo): ?array
    {
        return $this->matchRegNo($regNo, fn () => true);
    }

    /**
     * CIDP get-status-entity lookup: new or old number, and `entityType` (meta.json
     * vocabulary, or null for any) only narrows the match.
     */
    public function findForStatus(string $regNo, ?string $entityType): ?array
    {
        return $this->matchRegNo(
            $regNo,
            fn (array $case) => $entityType === null || ($case['meta']['entityType'] ?? '') === $entityType,
        );
    }

    /**
     * The case's old-format number: meta.json `oldRegNo` when set, otherwise the one
     * derived from its profile; '' when neither is known.
     */
    public function oldRegNo(array $case): string
    {
        $metaOld = $case['meta']['oldRegNo'] ?? null;

        if (is_string($metaOld) && trim($metaOld) !== '') {
            return trim($metaOld);
        }

        return SsmEntityStatus::oldRegNo((string) ($case['meta']['entityType'] ?? ''), $this->profile($case));
    }

    /**
     * meta.json is the identity of record even when the saved profile belongs to
     * another entity, so meta numbers (regNo, then oldRegNo) are tried across all
     * cases first, and profiles are only opened when no meta number matches.
     */
    private function matchRegNo(string $regNo, callable $filter): ?array
    {
        $regNo = trim($regNo);

        if ($regNo === '') {
            return null;
        }

        $candidates = array_values(array_filter($this->all(), $filter));

        foreach (['regNo', 'oldRegNo'] as $field) {
            foreach ($candidates as $case) {
                if (self::sameRegNo($case['meta'][$field] ?? null, $regNo)) {
                    return $case;
                }
            }
        }

        foreach ($candidates as $case) {
            $fromProfile = SsmEntityStatus::oldRegNo((string) ($case['meta']['entityType'] ?? ''), $this->profile($case));

            if (self::sameRegNo($fromProfile, $regNo)) {
                return $case;
            }
        }

        return null;
    }

    private static function sameRegNo(mixed $candidate, string $regNo): bool
    {
        if (! is_string($candidate) || trim($candidate) === '') {
            return false;
        }

        return strcasecmp(trim($candidate), $regNo) === 0;
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
