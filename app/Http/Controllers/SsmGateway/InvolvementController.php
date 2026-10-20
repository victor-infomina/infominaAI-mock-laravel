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

        $idValue = trim($validated['idValue']);
        $involvement = $this->involvements->resolve($idValue);

        if ($involvement === null) {
            return response()->json(['message' => 'No involvement record found for the given ID'], 404);
        }

        return response()->json([
            'message' => 'Submission received',
            'data' => ['selectionRefNo' => SsmGatewayInvolvementRepository::selectionRefNo($idValue)],
        ]);
    }

    public function report(string $idValue, string $identity): BinaryFileResponse|JsonResponse
    {
        $involvement = $this->involvements->find($idValue);
        $file = $involvement ? $this->involvements->reportFile($involvement, $identity) : null;

        if ($file === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->file($file, ['Content-Type' => 'application/pdf']);
    }
}
