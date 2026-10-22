<?php

namespace App\Services\SsmGateway;

use App\Services\SsmCaseRepository;

class CompanyMapper
{
    private const ENTITY = [
        'company' => ['entity' => 'Company', 'entity_type_id' => 1, 'code' => 'ROC', 'is_roc' => 1],
        'business' => ['entity' => 'Business', 'entity_type_id' => 2, 'code' => 'ROB', 'is_roc' => 0],
        'llp' => ['entity' => 'LLP', 'entity_type_id' => 3, 'code' => 'LLP', 'is_roc' => 0],
    ];

    public function __construct(private readonly SsmCaseRepository $cases) {}

    public function idFor(string $caseKey): int
    {
        return (int) sprintf('%u', crc32($caseKey));
    }

    public function findById(int $id): ?array
    {
        foreach ($this->cases->all() as $case) {
            if ($this->idFor($case['key']) === $id) {
                return $case;
            }
        }

        return null;
    }

    public function findBySlug(string $slug): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
            return null;
        }

        return $this->cases->find($slug);
    }

    public function findByRegNo(string $regNo): ?array
    {
        $regNo = trim($regNo);

        return $regNo === '' ? null : $this->cases->findByRegNoAny($regNo);
    }

    public function entityTypeCode(array $case): string
    {
        return $this->entity($case)['code'];
    }

    public function toSearchResult(array $case): array
    {
        $entity = $this->entity($case);
        $regNo = (string) ($case['meta']['regNo'] ?? '');

        return [
            'id' => $this->idFor($case['key']),
            'name' => (string) ($case['meta']['companyName'] ?? ''),
            'slug' => $case['key'],
            'reg_no' => $regNo,
            'reg_no_old' => $regNo,
            'entity' => $entity['entity'],
            'entity_type_id' => $entity['entity_type_id'],
        ];
    }

    public function toInfo(array $case): array
    {
        $entity = $this->entity($case);
        $regNo = (string) ($case['meta']['regNo'] ?? '');

        return [
            'is_roc' => $entity['is_roc'],
            'id' => $this->idFor($case['key']),
            'name' => (string) ($case['meta']['companyName'] ?? ''),
            'registration_no' => $regNo,
            'old_registration_no' => $regNo,
            'entity_type' => $entity['entity'],
            'entity_type_code' => $entity['code'],
            'origin' => 'L',
        ];
    }

    public function reportUrl(array $case): string
    {
        return route('ssm-mock.report', ['caseKey' => $case['key']]);
    }

    private function entity(array $case): array
    {
        $type = strtolower((string) ($case['meta']['entityType'] ?? 'company'));

        return self::ENTITY[$type] ?? self::ENTITY['company'];
    }
}
