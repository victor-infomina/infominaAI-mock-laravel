<?php

namespace App\Http\Controllers\WebhookInbox;

use App\Http\Controllers\Controller;
use App\Services\WebhookInbox\WebhookInboxRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function __construct(private readonly WebhookInboxRepository $repository)
    {
    }

    public function index(): View
    {
        return view('webhook-inbox.index', [
            'inboxes' => $this->repository->all(),
            'responseModes' => WebhookInboxRepository::RESPONSE_MODES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'regex:'.WebhookInboxRepository::SLUG_PATTERN],
            'identifier' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'response_mode' => ['required', Rule::in(WebhookInboxRepository::RESPONSE_MODES)],
        ]);

        if ($this->repository->find($data['slug']) !== null) {
            return back()->withErrors(['slug' => 'An inbox with this slug already exists.'])->withInput();
        }

        $this->repository->create($data['slug'], $data);

        return redirect()->route('webhook-inbox.show', $data['slug']);
    }

    public function show(string $slug): View
    {
        $inbox = $this->repository->find($slug);
        abort_if($inbox === null, 404);

        return view('webhook-inbox.show', [
            'inbox' => $inbox,
            'maskedSecret' => $this->mask($inbox['secret']),
            'events' => $this->repository->events($slug),
            'responseModes' => WebhookInboxRepository::RESPONSE_MODES,
            'webhookUrl' => url('/webhook-inbox/'.$slug.'/'.$inbox['token']),
            'callbackUrl' => url('/webhook-inbox/'.$slug.'/'.$inbox['token'].'/callback'),
            'readUrl' => url('/admin-api/webhook-inbox/'.$slug.'/events'),
        ]);
    }

    public function updateSettings(Request $request, string $slug): RedirectResponse
    {
        abort_if($this->repository->find($slug) === null, 404);

        $data = $request->validate([
            'identifier' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'clear_secret' => ['nullable', 'boolean'],
            'response_mode' => ['required', Rule::in(WebhookInboxRepository::RESPONSE_MODES)],
        ]);

        $settings = [
            'identifier' => $data['identifier'] ?? null,
            'response_mode' => $data['response_mode'],
        ];
        // A blank secret field means "keep"; the checkbox means "remove".
        if (! empty($data['clear_secret'])) {
            $settings['secret'] = null;
        } elseif (($data['secret'] ?? '') !== '') {
            $settings['secret'] = $data['secret'];
        }

        $this->repository->updateSettings($slug, $settings);

        return redirect()->route('webhook-inbox.show', $slug)->with('notice', 'Settings saved.');
    }

    public function regenerateToken(string $slug): RedirectResponse
    {
        abort_if($this->repository->find($slug) === null, 404);

        $this->repository->regenerateToken($slug);

        return redirect()->route('webhook-inbox.show', $slug)
            ->with('notice', 'Token regenerated. Update webhook_url and callback_url on the BE.');
    }

    public function clear(string $slug): RedirectResponse
    {
        abort_if($this->repository->find($slug) === null, 404);

        $count = $this->repository->clear($slug);

        return redirect()->route('webhook-inbox.show', $slug)->with('notice', "Deleted {$count} event(s).");
    }

    public function event(string $slug, string $id): View
    {
        abort_if($this->repository->find($slug) === null, 404);
        $event = $this->repository->findEvent($slug, $id);
        abort_if($event === null, 404);

        return view('webhook-inbox.event', [
            'slug' => $slug,
            'event' => $event,
            'json' => json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function mask(?string $secret): ?string
    {
        if ($secret === null) {
            return null;
        }

        return '••••'.substr($secret, -4);
    }
}
