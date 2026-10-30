<x-layout title="Webhook Inbox">
    <div class="mx-auto max-w-4xl px-6 py-12">
        <div class="mb-10">
            <p class="eyebrow text-ink/40">Partner-side receiver</p>
            <h1 class="mt-1 font-mono text-2xl font-bold">Webhook Inbox</h1>
            <p class="mt-2 max-w-2xl text-sm text-ink/60">
                Each inbox is a token-protected URL infominaAI-BE can deliver client-API webhooks and callbacks to.
                Set the client key and secret to have every hash and signature (X-Signature, callback ts + signature) verified. Events are kept for 7 days, max 500 per inbox.
            </p>
        </div>

        <div class="folder-card mb-12 mt-8">
            <div class="folder-card__tab">Create an inbox</div>
            <div class="folder-card__body">
                <form method="POST" action="{{ route('webhook-inbox.store') }}" class="flex flex-wrap items-end gap-5">
                    @csrf
                    <div class="min-w-[160px]">
                        <label for="slug" class="field-label">Slug</label>
                        <input type="text" name="slug" id="slug" required class="input" value="{{ old('slug') }}" placeholder="dev-myhasil">
                    </div>
                    <div class="min-w-[200px] flex-1">
                        <label for="identifier" class="field-label">Client key (identifier)</label>
                        <input type="text" name="identifier" id="identifier" class="input" value="{{ old('identifier') }}">
                    </div>
                    <div class="min-w-[200px] flex-1">
                        <label for="secret" class="field-label">Secret key</label>
                        <input type="password" name="secret" id="secret" class="input" autocomplete="off">
                    </div>
                    <div>
                        <label for="response_mode" class="field-label">Respond</label>
                        <select name="response_mode" id="response_mode" class="input">
                            @foreach ($responseModes as $mode)
                                <option value="{{ $mode }}" @selected(old('response_mode', 'ok') === $mode)>{{ $mode }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn-primary">Create</button>
                </form>
                @error('slug')
                    <p class="mt-3 text-sm text-stamp">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <p class="eyebrow mb-2 text-ink/40">{{ count($inboxes) }} inbox(es)</p>
        <table class="ledger-table">
            <thead>
                <tr><th>Slug</th><th>Client key</th><th>Secret</th><th>Respond</th><th>Created</th></tr>
            </thead>
            <tbody>
                @forelse ($inboxes as $inbox)
                    <tr>
                        <td class="font-mono"><a href="{{ route('webhook-inbox.show', $inbox['slug']) }}" class="hover:text-wire">{{ $inbox['slug'] }}</a></td>
                        <td class="font-mono text-xs">{{ $inbox['identifier'] ?? '—' }}</td>
                        <td>{{ $inbox['secret'] ? 'set' : '—' }}</td>
                        <td><span class="badge {{ $inbox['response_mode'] === 'ok' ? 'badge-active' : 'badge-revoked' }}">{{ $inbox['response_mode'] }}</span></td>
                        <td class="font-mono text-xs text-ink/55">{{ $inbox['created_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-ink/45">No inboxes yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
