<?php

namespace App\Http\Controllers\WebhookInbox;

use App\Http\Controllers\Controller;
use App\Services\WebhookInbox\WebhookInboxRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

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

        // An unparseable since would otherwise drop the filter and return every
        // event, letting a poll for "new deliveries" pass on stale ones.
        if (is_string($since) && $since !== '' && ! $this->isParseable($since)) {
            return response()->json(['message' => 'since must be an ISO-8601 timestamp (URL-encode the + in an offset).'], 422);
        }

        return response()->json([
            'inbox' => $slug,
            'events' => $this->repository->events(
                $slug,
                is_string($since) && $since !== '' ? $since : null,
                is_string($referenceId) && $referenceId !== '' ? $referenceId : null,
            ),
        ]);
    }

    private function isParseable(string $value): bool
    {
        try {
            Carbon::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
