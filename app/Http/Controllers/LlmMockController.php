<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LlmMockController extends Controller
{
    public function invoke(Request $request, string $modelOp): JsonResponse
    {
        $operation = strtolower($modelOp);

        if (str_contains($operation, 'summar')) {
            return $this->summarizeResponse();
        }

        if (str_contains($operation, 'recommend')) {
            return $this->recommendResponse($request);
        }

        if (str_contains($operation, 'search')) {
            return $this->searchResponse();
        }

        return response()->json([
            'output' => "MOCK LLM — Unrecognised operation \"{$modelOp}\". (infominaAI-ssm-mock)",
        ]);
    }

    private function summarizeResponse(): JsonResponse
    {
        return response()->json([
            'output' => 'MOCK SUMMARIZATION — This is a mocked executive summary for local/e2e testing. '
                .'The company demonstrates a stable operating profile with consistent shareholding '
                .'and an experienced board. No material adverse signals detected. (infominaAI-ssm-mock)',
        ]);
    }

    private function searchResponse(): JsonResponse
    {
        return response()->json([
            'output' => 'MOCK SEARCH/BUSINESS INFORMATION — This is mocked business information for '
                .'local/e2e testing. The company operates within its registered activities and '
                .'maintains active status with no notable red flags. (infominaAI-ssm-mock)',
        ]);
    }

    private function recommendResponse(Request $request): JsonResponse
    {
        $products = $request->input('input.products', []);
        $sessionId = $request->input('input.session_id');

        $result = array_map(
            fn (array $product) => [
                ($product['product_id'] ?? '') => $this->buildProductRecommendation($product),
            ],
            $products
        );

        return response()->json([
            'output' => [
                'products' => $result,
                'session_id' => $sessionId,
            ],
        ]);
    }

    private function buildProductRecommendation(array $product): array
    {
        $isPurchasable = ($product['able_to_purchase'] ?? null) === true || ($product['able_to_purchase'] ?? null) === 'YES';
        $isPurchased = ($product['purchased'] ?? null) === true || ($product['purchased'] ?? null) === 'YES';
        $purchasable = $isPurchasable && ! $isPurchased;

        return match ($product['product_id'] ?? null) {
            'tin' => [
                'recommendation_text' => 'MOCK RECOMMENDATION — Verify tax compliance with the Tax Identification Number (TIN). '
                    .($purchasable
                        ? 'Click the TIN column to purchase and retrieve this record.'
                        : 'This record is unavailable or already purchased.')
                    .' (infominaAI-ssm-mock)',
                'actions' => $purchasable ? [['action_type' => 'PURCHASE', 'details' => new \stdClass()]] : [],
            ],
            'bir' => [
                'recommendation_text' => 'MOCK RECOMMENDATION — The Business Information Report (BIR) offers a fuller view '
                    .'of financial health and credit risk. '
                    .($purchasable
                        ? 'Purchase the full report to unlock these insights.'
                        : 'This report is unavailable or already purchased.')
                    .' (infominaAI-ssm-mock)',
                'actions' => $purchasable ? [['action_type' => 'PURCHASE', 'details' => new \stdClass()]] : [],
            ],
            'nearby_companies' => (function () use ($product) {
                $details = $product['details'] ?? [];

                return [
                    'recommendation_text' => sprintf(
                        'MOCK RECOMMENDATION — Found %d business(es) operating nearby. Review the map to explore potential business relationships. (infominaAI-ssm-mock)',
                        count($details)
                    ),
                    'actions' => count($details) > 0 ? [['action_type' => 'MAP_COMPANY', 'details' => $details]] : [],
                ];
            })(),
            default => [
                'recommendation_text' => sprintf(
                    'MOCK RECOMMENDATION — No specific insight generated for "%s". (infominaAI-ssm-mock)',
                    $product['product_id'] ?? ''
                ),
                'actions' => [],
            ],
        };
    }
}
