<?php

namespace App\Http\Controllers;

use App\Services\SsmCaseRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SsmMockController extends Controller
{
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
                'searchEntity' => array_map(fn (array $case) => [
                    'companyName' => $case['meta']['companyName'],
                    'companyNo' => $case['meta']['regNo'],
                    'oldCompanyNo' => $case['meta']['regNo'],
                    'entityType' => $case['meta']['entityType'],
                    'entityCode' => $case['meta']['entityType'],
                ], $matches),
                'errorMsg' => null,
            ],
        ]);
    }
}
