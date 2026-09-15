<x-layout title="API Tokens">
    <div class="mx-auto max-w-4xl px-6 py-12">
        <div class="mb-10">
            <p class="eyebrow text-ink/40">Credentials Ledger</p>
            <h1 class="mt-1 font-mono text-2xl font-bold">API Tokens</h1>
            <p class="mt-2 max-w-2xl text-sm text-ink/60">
                Keys issued here authenticate infominaAI-BE's gateway calls against this mock.
                Each purpose maps to one real integration point — issue narrowly, revoke what's unused.
            </p>
        </div>

        @if (session('generatedSecret'))
            <div class="folder-card relative mb-12 mt-8">
                <div class="folder-card__tab text-stamp/80">One-time reveal</div>
                <span class="specimen">Record once</span>
                <div class="folder-card__body">
                    <p class="text-sm text-ink/70">This secret is shown once and cannot be recovered — copy both values into the target <code class="font-mono text-xs">.env</code> now.</p>
                    <dl class="mt-5 font-mono text-sm">
                        <div class="flex flex-wrap gap-3"><dt class="w-16 shrink-0 text-ink/45">Key</dt><dd class="break-all">{{ session('generatedKey') }}</dd></div>
                    </dl>
                    <div class="perforated my-4"></div>
                    <dl class="font-mono text-sm">
                        <div class="flex flex-wrap gap-3"><dt class="w-16 shrink-0 text-ink/45">Secret</dt><dd class="break-all">{{ session('generatedSecret') }}</dd></div>
                    </dl>
                </div>
            </div>
        @endif

        <div class="folder-card mb-12 mt-8">
            <div class="folder-card__tab">Issue a token</div>
            <div class="folder-card__body">
                <form method="POST" action="{{ route('tokens.store') }}" class="flex flex-wrap items-end gap-5">
                    @csrf
                    <div class="min-w-[200px] flex-1">
                        <label for="label" class="field-label">Label</label>
                        <input type="text" name="label" id="label" required class="input" value="{{ old('label') }}" placeholder="e.g. be-dev-gateway">
                    </div>
                    <div>
                        <label for="purpose" class="field-label">Purpose</label>
                        <select name="purpose" id="purpose" class="input">
                            <option value="gateway">gateway — infominaAI-BE (SSM)</option>
                            <option value="admin_sync">admin_sync — local case-sync tool</option>
                            <option value="asiaverify">asiaverify — infominaAI-BE (AsiaVerify)</option>
                            <option value="dnb">dnb — infominaAI-BE (DNB)</option>
                        </select>
                    </div>
                    <div>
                        <label for="expires_in_days" class="field-label">Expires</label>
                        <select name="expires_in_days" id="expires_in_days" class="input">
                            <option value="30">30 days</option>
                            <option value="90">90 days</option>
                            <option value="180">180 days</option>
                            <option value="365">365 days</option>
                            <option value="">No expiry</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary">Generate</button>
                </form>
                @error('label')
                    <p class="mt-3 text-sm text-stamp">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <p class="eyebrow mb-2 text-ink/40">{{ $apiClients->count() }} issued</p>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Purpose</th>
                    <th>Key</th>
                    <th>Status</th>
                    <th>Expires</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($apiClients as $apiClient)
                    <tr>
                        <td class="font-medium">{{ $apiClient->label }}</td>
                        <td class="font-mono text-xs uppercase text-ink/55">{{ $apiClient->purpose }}</td>
                        <td class="font-mono text-xs">{{ $apiClient->key }}</td>
                        <td>
                            @if ($apiClient->isRevoked())
                                <span class="badge-revoked">Revoked</span>
                            @elseif ($apiClient->isExpired())
                                <span class="badge-expired">Expired</span>
                            @else
                                <span class="badge-active">Active</span>
                            @endif
                        </td>
                        <td class="text-ink/55">{{ $apiClient->expires_at?->toDateString() ?? 'Never' }}</td>
                        <td class="text-right">
                            @if ($apiClient->isActive())
                                <form method="POST" action="{{ route('tokens.revoke', $apiClient) }}">
                                    @csrf
                                    <button type="submit" class="btn-danger">Revoke</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center text-ink/40">No tokens yet — issue one above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
