<?php

namespace App\Http\Controllers\SsmGateway;

use App\Http\Controllers\Controller;
use App\Services\SsmGateway\CompanyMapper;
use App\Services\SsmGateway\ProductCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __construct(
        private readonly CompanyMapper $companies,
        private readonly ProductCatalogue $catalogue,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $term = $request->input('search');
        $case = is_string($term) ? $this->companies->findByRegNo($term) : null;
        $data = $case ? [$this->companies->toSearchResult($case)] : [];

        return response()->json([
            'data' => $data,
            'total' => count($data),
            'is_more_than_limit' => false,
        ]);
    }

    public function info(Request $request): JsonResponse
    {
        $slug = $request->input('slug');
        $case = is_string($slug) ? $this->companies->findBySlug($slug) : null;

        if ($case === null) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        return response()->json($this->companies->toInfo($case));
    }

    public function productList(): JsonResponse
    {
        return response()->json($this->catalogue->listResponse());
    }

    public function productListByCompany(Request $request): JsonResponse
    {
        $slug = $request->input('slug');

        if (! is_string($slug) || $this->companies->findBySlug($slug) === null) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        return response()->json($this->catalogue->listResponse());
    }
}
