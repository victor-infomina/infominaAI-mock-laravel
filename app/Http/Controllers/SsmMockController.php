<?php

namespace App\Http\Controllers;

use App\Services\SsmCaseRepository;
use App\Services\SsmEntityStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SsmMockController extends Controller
{
    /** get-status-entity's entityType vocabulary (CIDP spec 4.0) -> meta.json entityType. */
    private const STATUS_ENTITY_TYPES = [
        'company' => 'company',
        'business' => 'business',
        'limited_liability_partnerships' => 'llp',
    ];

    public function __construct(private readonly SsmCaseRepository $cases)
    {
    }

    public function searchEntity(Request $request): JsonResponse
    {
        $entityType = (string) $request->input('entityType');
        $regNo = $request->input('regNo');
        $name = $request->input('name');

        if (is_string($regNo) && $regNo !== '') {
            $case = $this->cases->findByRegNo($regNo, $entityType);
            $matches = $case ? [$case] : [];
        } elseif (is_string($name) && $name !== '') {
            $matches = $this->cases->searchByName($name, $entityType);
        } else {
            $matches = [];
        }

        if ($matches === []) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'getSearchEntity' => [
                'searchEntity' => [
                    'data' => array_map(fn (array $case) => [
                        'companyName' => $case['meta']['companyName'],
                        'companyNo' => $case['meta']['regNo'],
                        'oldCompanyNo' => $this->cases->oldRegNo($case) ?: $case['meta']['regNo'],
                        'entityType' => $case['meta']['entityType'],
                        'entityCode' => $case['meta']['entityType'],
                    ], $matches),
                ],
                'errorMsg' => null,
            ],
        ]);
    }

    /**
     * CIDP get-status-entity (AE-522). regNo is required (new or old format); entityType is
     * optional and limited to the three values the CIDP spec accepts. Anything else, and
     * unknown numbers, get the mock's usual not_found (prod's error shape is not captured).
     */
    public function statusEntity(Request $request): JsonResponse
    {
        $regNo = $request->input('regNo');
        $entityType = $request->input('entityType');

        if (! is_string($regNo) || trim($regNo) === '') {
            return response()->json(['error' => 'not_found'], 404);
        }

        if ($entityType !== null && $entityType !== '') {
            $entityType = is_string($entityType) ? self::STATUS_ENTITY_TYPES[$entityType] ?? null : null;

            if ($entityType === null) {
                return response()->json(['error' => 'not_found'], 404);
            }
        } else {
            $entityType = null;
        }

        $case = $this->cases->findForStatus($regNo, $entityType);

        if ($case === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $type = (string) ($case['meta']['entityType'] ?? '');
        $profile = $this->cases->profile($case);

        return response()->json([
            'getStatusEntity' => [
                'clientRefNo' => (string) $request->header('x-Client-Ref-No', ''),
                'requestRefNo' => $case['key'],
                'statusEntity' => [
                    'data' => [
                        'companyName' => (string) ($case['meta']['companyName'] ?? ''),
                        'companyNo' => (string) ($case['meta']['regNo'] ?? ''),
                        'oldCompanyNo' => $this->cases->oldRegNo($case),
                        'entityType' => SsmEntityStatus::entityTypeLabel($type),
                        'companyStatus' => SsmEntityStatus::status($case['meta'], $type, $profile, now()),
                    ],
                ],
            ],
        ]);
    }

    public function companyProfile(Request $request): JsonResponse
    {
        return $this->profileResponse($request, 'company', 'company-profile.json', 'getCompProfile');
    }

    public function businessProfile(Request $request): JsonResponse
    {
        return $this->profileResponse($request, 'business', 'business-profile.json', 'getBizProfile');
    }

    public function llpProfile(Request $request): JsonResponse
    {
        return $this->profileResponse($request, 'llp', 'llp-profile.json', 'getLlpCurrentProfile');
    }

    public function orderDocument(Request $request): JsonResponse
    {
        $requestRefNo = (string) $request->input('requestRefNo');
        $case = $this->cases->find($requestRefNo);

        if ($case === null || ! is_file($case['path'].'/report.pdf')) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'getOrderDocument' => [
                'data' => [
                    'status' => 'Completed',
                    'documentUrl' => route('ssm-mock.report', ['caseKey' => $case['key']]),
                ],
            ],
        ]);
    }

    public function imageList(Request $request): JsonResponse
    {
        $regNo = (string) $request->input('regNo');
        $case = $this->cases->findByRegNoAny($regNo);
        $documents = $case ? $this->cases->idamanDocuments($case) : null;

        if ($documents === null) {
            return response()->json(['getImageView' => ['errorMsg' => 'not found']]);
        }

        return response()->json([
            'getImageView' => [
                'documentInfos' => [
                    'documentInfos' => $documents,
                ],
            ],
        ]);
    }

    public function image(Request $request): JsonResponse
    {
        $regNo = (string) $request->input('regNo');
        $verId = (string) $request->input('verId');

        $case = $this->cases->findByRegNoAny($regNo);
        $documents = $case ? $this->cases->idamanDocuments($case) : null;
        $hasEntry = $documents !== null && $this->containsVerId($documents, $verId);
        $filePath = $hasEntry ? $this->cases->idamanDocumentFile($case, $verId) : null;

        if ($filePath === null) {
            return response()->json(['getImage' => ['errorMsg' => 'not found']]);
        }

        return response()->json([
            'getImage' => [
                'docContent' => base64_encode(file_get_contents($filePath)),
            ],
        ]);
    }

    private function containsVerId(array $documents, string $verId): bool
    {
        foreach ($documents as $document) {
            if (($document['verId'] ?? null) === $verId) {
                return true;
            }
        }

        return false;
    }

    private function profileResponse(Request $request, string $entityType, string $fixtureFile, string $envelopeKey): JsonResponse
    {
        $regNo = (string) $request->input('regNo');
        $case = $this->cases->findByRegNo($regNo, $entityType);

        if ($case === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $fixturePath = $case['path'].'/'.$fixtureFile;

        if (! is_file($fixturePath)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = json_decode(file_get_contents($fixturePath), true);
        $data[$envelopeKey]['requestRefNo'] = $case['key'];

        return response()->json($data);
    }
}
