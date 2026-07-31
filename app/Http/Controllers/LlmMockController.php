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
}
