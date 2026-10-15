<?php

namespace App\Http\Controllers\SsmGateway;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SupportController extends Controller
{
    public function fileUpload(Request $request): JsonResponse
    {
        $file = $request->file('file');

        if ($file === null) {
            return response()->json(['message' => 'No file uploaded'], 422);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $filename = (string) Str::uuid().'.'.$ext;

        return response()->json([[
            'url' => url('/ssm-gateway/uploads/'.$filename),
            'filename' => $filename,
            'ori_filename' => $file->getClientOriginalName(),
            'ext' => '.'.$ext,
        ]]);
    }

    public function helpdeskSubmit(Request $request): JsonResponse
    {
        $id = Cache::increment('ssm_gateway_helpdesk_seq');
        $now = now()->getTimestampMs();

        return response()->json([
            'message' => 'Ticket created',
            'error' => false,
            'code' => 200,
            'results' => [
                'id' => $id,
                'internal_ticket_no' => 'HD-MOCK-'.$id,
                'creator_id' => 1,
                'complainant_name' => (string) $request->input('name', ''),
                'complainant_email' => (string) $request->input('email', ''),
                'complainant_phone' => (string) $request->input('phone', ''),
                'subject' => (string) $request->input('subject', ''),
                'desc' => (string) $request->input('desc', ''),
                'external_ticket_no' => (string) $request->input('external_ticket_no', ''),
                'client_id' => (int) $request->attributes->get('ssm_gateway_client_id'),
                'status_id' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'status' => ['id' => 1, 'label' => 'Open', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }
}
