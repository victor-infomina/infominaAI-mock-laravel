<?php

namespace App\Http\Controllers\SsmGateway;

use App\Http\Controllers\Controller;
use App\Services\SsmGatewayInvolvementRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InvolvementController extends Controller
{
    public function __construct(private readonly SsmGatewayInvolvementRepository $involvements) {}

    public function submission(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idType' => ['nullable', 'string'],
            'idValue' => ['required', 'string'],
        ]);

        $involvement = $this->involvements->find(trim($validated['idValue']));

        if ($involvement === null) {
            return response()->json(['message' => 'No involvement record found for the given ID'], 404);
        }

        return response()->json([
            'message' => 'Submission received',
            'data' => ['selectionRefNo' => SsmGatewayInvolvementRepository::selectionRefNo($involvement['key'])],
        ]);
    }

    public function report(string $idValue): BinaryFileResponse|JsonResponse
    {
        $involvement = $this->involvements->find($idValue);
        $file = $involvement ? $this->involvements->reportFile($involvement) : null;

        if ($file === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->file($file, ['Content-Type' => 'application/pdf']);
    }
}
