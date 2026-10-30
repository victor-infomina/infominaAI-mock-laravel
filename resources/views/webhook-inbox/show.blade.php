<x-layout :title="'Inbox · '.$inbox['slug']">
    <div class="mx-auto max-w-5xl px-6 py-12">
        <a href="{{ route('webhook-inbox.index') }}" class="eyebrow text-ink/40 hover:text-wire">← All inboxes</a>

        <div class="folder-card relative mb-10 mt-8">
            <div class="folder-card__tab">Inbox · {{ $inbox['slug'] }}</div>
            <div class="folder-card__body">
                <p class="text-sm text-ink/70">Point the BE's <code class="font-mono text-xs">api_configuration</code> row here. The token in the URL is the credential — regenerate it if it leaks.</p>
                <dl class="mt-4 font-mono text-sm">
                    <div class="flex flex-wrap gap-3 py-1"><dt class="w-28 shrink-0 text-ink/45">webhook_url</dt><dd class="break-all">{{ $webhookUrl }}</dd></div>
                    <div class="flex flex-wrap gap-3 py-1"><dt class="w-28 shrink-0 text-ink/45">callback_url</dt><dd class="break-all">{{ $callbackUrl }}</dd></div>
                    <div class="flex flex-wrap gap-3 py-1"><dt class="w-28 shrink-0 text-ink/45">script read</dt><dd class="break-all">{{ $readUrl }} <span class="text-ink/45">(x-Gateway-APIKey / x-Gateway-APISecret, purpose admin_sync)</span></dd></div>
                </dl>
                <form method="POST" action="{{ route('webhook-inbox.regenerate', $inbox['slug']) }}" class="mt-4" onsubmit="return confirm('Regenerate the token? The current URLs stop working immediately.')">
                    @csrf
                    <button type="submit" class="btn-danger">Regenerate token</button>
                </form>
            </div>
        </div>

        @if (session('notice'))
            <div class="notice-info mb-8">{{ session('notice') }}</div>
        @endif

        <div class="folder-card mb-12">
            <div class="folder-card__tab">Settings</div>
            <div class="folder-card__body">
                <form method="POST" action="{{ route('webhook-inbox.settings', $inbox['slug']) }}" class="flex flex-wrap items-end gap-5">
                    @csrf
                    <div class="min-w-[200px] flex-1">
                        <label for="identifier" class="field-label">Client key (identifier)</label>
                        <input type="text" name="identifier" id="identifier" class="input" value="{{ old('identifier', $inbox['identifier']) }}">
                    </div>
                    <div class="min-w-[200px] flex-1">
                        <label for="secret" class="field-label">Secret key {{ $maskedSecret ? '(current: '.$maskedSecret.')' : '(not set)' }}</label>
                        <input type="password" name="secret" id="secret" class="input" autocomplete="off" placeholder="leave blank to keep">
                        <label class="mt-2 flex items-center gap-2 text-xs text-ink/60"><input type="checkbox" name="clear_secret" value="1" class="accent-wire"> Remove secret</label>
                    </div>
                    <div>
                        <label for="response_mode" class="field-label">Respond</label>
                        <select name="response_mode" id="response_mode" class="input">
                            @foreach ($responseModes as $mode)
                                <option value="{{ $mode }}" @selected(old('response_mode', $inbox['response_mode']) === $mode)>{{ $mode }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn-primary">Save</button>
                </form>
                <p class="mt-3 text-xs text-ink/50">ok → 200 · error → 500 (BE retries 3×, then 5/15/60/360 min) · timeout → sleeps {{ config('ssm_mock.webhook_inbox_timeout_seconds') }} s (BE gives up at 15 s)</p>
                @error('response_mode')
                    <p class="mt-3 text-sm text-stamp">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="mb-2 flex items-center justify-between">
            <p class="eyebrow text-ink/40">{{ count($events) }} event(s), newest first</p>
            <form method="POST" action="{{ route('webhook-inbox.clear', $inbox['slug']) }}" onsubmit="return confirm('Delete all events in this inbox?')">
                @csrf
                <button type="submit" class="btn-danger">Clear events</button>
            </form>
        </div>
        <table class="ledger-table">
            <thead>
                <tr><th>Received (UTC)</th><th>Kind</th><th>Event</th><th>Reference</th><th>Seq</th><th>Final</th><th>Retry</th><th>Hash</th><th>Signature</th><th>Sent</th></tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td class="font-mono text-xs"><a href="{{ route('webhook-inbox.event', [$inbox['slug'], $event['id']]) }}" class="hover:text-wire">{{ $event['received_at'] }}</a></td>
                        <td>{{ $event['kind'] ?? '—' }}</td>
                        <td class="font-mono text-xs">{{ $event['event_type'] ?? '—' }}</td>
                        <td class="font-mono text-xs">{{ $event['reference_id'] ?? '—' }}</td>
                        <td>{{ $event['sequence'] ?? '—' }}</td>
                        <td>{{ is_null($event['final'] ?? null) ? '—' : ($event['final'] ? 'yes' : 'no') }}</td>
                        <td>{{ is_null($event['retry'] ?? null) ? '—' : ($event['retry'] ? 'yes' : 'no') }}</td>
                        <td><span class="badge {{ ($event['hash_status'] ?? '') === 'valid' ? 'badge-active' : (($event['hash_status'] ?? '') === 'invalid' ? 'badge-revoked' : 'badge-expired') }}">{{ $event['hash_status'] ?? 'unchecked' }}</span></td>
                        <td><span class="badge {{ ($event['signature_status'] ?? '') === 'valid' ? 'badge-active' : (in_array($event['signature_status'] ?? '', ['invalid', 'stale']) ? 'badge-revoked' : 'badge-expired') }}">{{ $event['signature_status'] ?? 'unchecked' }}</span></td>
                        <td class="font-mono text-xs">{{ $event['response_status'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-ink/45">Nothing received yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
