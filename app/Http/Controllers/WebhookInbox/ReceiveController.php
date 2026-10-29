<?php

namespace App\Http\Controllers\WebhookInbox;

use App\Http\Controllers\Controller;
use App\Services\WebhookInbox\WebhookHashVerifier;
use App\Services\WebhookInbox\WebhookInboxRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Partner-side receiver for infominaAI-BE client-API deliveries. Public on
 * purpose: the BE (webhook) and the user's browser (callback) hit these.
 * The per-inbox token in the path is the only credential; a wrong token is
 * indistinguishable from an unknown slug (404, nothing stored).
 */
class ReceiveController extends Controller
{
    public function __construct(private readonly WebhookInboxRepository $repository)
    {
    }

    public function webhook(Request $request, string $slug, string $token): JsonResponse
    {
        $inbox = $this->repository->findByToken($slug, $token);
        abort_if($inbox === null, 404);

        $raw = $request->getContent();
        abort_if(strlen($raw) > WebhookInboxRepository::MAX_BODY_BYTES, 413);

        $body = $this->decodeBody($raw);
        $status = $inbox['response_mode'] === 'error' ? 500 : 200;

        $this->repository->storeEvent($slug, [
            'kind' => 'webhook',
            'event_type' => $this->stringOrNull($body['event'] ?? null) ?? 'bulk-purchase-completed',
            'reference_id' => $this->stringOrNull($body['referenceId'] ?? null),
            'sequence' => is_scalar($body['sequence'] ?? null) ? $body['sequence'] : null,
            'final' => $this->boolOrNull($body['final'] ?? null),
            'retry' => $this->boolOrNull($body['retry'] ?? null),
            'hash' => $this->stringOrNull($body['hash'] ?? null),
            'hash_status' => WebhookHashVerifier::status(
                $inbox['identifier'],
                $inbox['secret'],
                $this->stringOrNull($body['referenceId'] ?? null),
                $this->stringOrNull($body['hash'] ?? null),
            ),
            'response_mode' => $inbox['response_mode'],
            'response_status' => $status,
            'headers' => $this->headerSubset($request),
            'body' => $body,
        ]);

        if ($inbox['response_mode'] === 'timeout') {
            sleep((int) config('ssm_mock.webhook_inbox_timeout_seconds'));
        }

        return response()->json(['received' => $status === 200], $status);
    }

    public function callback(Request $request, string $slug, string $token): View
    {
        $inbox = $this->repository->findByToken($slug, $token);
        abort_if($inbox === null, 404);

        $query = $request->query();
        $referenceId = $this->stringOrNull($query['referenceId'] ?? null);
        $hash = $this->stringOrNull($query['hash'] ?? null);

        $event = $this->repository->storeEvent($slug, [
            'kind' => 'callback',
            'event_type' => 'callback',
            'reference_id' => $referenceId,
            'sequence' => null,
            'final' => null,
            'retry' => null,
            'hash' => $hash,
            'hash_status' => WebhookHashVerifier::status($inbox['identifier'], $inbox['secret'], $referenceId, $hash),
            'response_mode' => null,
            'response_status' => 200,
            'headers' => $this->headerSubset($request),
            'body' => null,
            'query' => $query,
        ]);

        return view('webhook-inbox.callback', ['slug' => $slug, 'event' => $event]);
    }

    private function decodeBody(string $raw): array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['raw' => $raw];
    }

    /** @return array<string, string|null> */
    private function headerSubset(Request $request): array
    {
        return [
            'content-type' => $request->header('Content-Type'),
            'user-agent' => $request->header('User-Agent'),
            'content-length' => $request->header('Content-Length'),
        ];
    }

    private function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
