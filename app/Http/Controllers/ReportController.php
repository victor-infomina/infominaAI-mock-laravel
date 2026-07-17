<?php

namespace App\Http\Controllers;

use App\Services\SsmCaseRepository;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function __construct(private readonly SsmCaseRepository $cases)
    {
    }

    public function show(string $caseKey): BinaryFileResponse|JsonResponse
    {
        $case = $this->cases->find($caseKey);
        $pdfPath = $case ? $case['path'].'/report.pdf' : null;

        if ($pdfPath === null || ! is_file($pdfPath)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->file($pdfPath, ['Content-Type' => 'application/pdf']);
    }
}
