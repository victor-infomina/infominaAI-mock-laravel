<?php

namespace App\Http\Controllers\WebhookInbox;

use App\Http\Controllers\Controller;
use App\Services\WebhookInbox\WebhookInboxRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Script-facing read of an inbox, guarded by the same admin_sync credential
 * the case-sync tool uses (routes/api.php). Lets a test poll for a delivery
 * instead of watching the admin page.
 */
class AdminApiController extends Controller
{
    public function __construct(private readonly WebhookInboxRepository $repository)
    {
    }

    public function events(Request $request, string $slug): JsonResponse
    {
        abort_if($this->repository->find($slug) === null, 404);

        $since = $request->query('since');
        $referenceId = $request->query('reference_id');

        return response()->json([
            'inbox' => $slug,
            'events' => $this->repository->events(
                $slug,
                is_string($since) && $since !== '' ? $since : null,
                is_string($referenceId) && $referenceId !== '' ? $referenceId : null,
            ),
        ]);
    }
}
