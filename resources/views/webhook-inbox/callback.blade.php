<x-layout title="Callback received">
    <div class="mx-auto max-w-3xl px-6 py-12">
        <div class="folder-card relative mb-10 mt-8">
            <div class="folder-card__tab">Callback landing · {{ $slug }}</div>
            <span class="specimen">Received</span>
            <div class="folder-card__body">
                <h1 class="font-mono text-xl font-bold">Callback received</h1>
                <p class="mt-1 font-mono text-sm text-ink/55">{{ $event['received_at'] }} · event {{ $event['id'] }}</p>
                <p class="mt-3 text-sm">
                    Hash:
                    <span class="badge {{ $event['hash_status'] === 'valid' ? 'badge-active' : ($event['hash_status'] === 'invalid' ? 'badge-revoked' : 'badge-expired') }}">{{ $event['hash_status'] }}</span>
                    · Signature:
                    <span class="badge {{ ($event['signature_status'] ?? '') === 'valid' ? 'badge-active' : (in_array($event['signature_status'] ?? '', ['invalid', 'stale']) ? 'badge-revoked' : 'badge-expired') }}">{{ $event['signature_status'] ?? 'unchecked' }}</span>
                </p>
            </div>
        </div>

        <div class="folder-card">
            <div class="folder-card__tab">Query parameters</div>
            <div class="folder-card__body">
                @if (count($event['query']) === 0)
                    <p class="text-sm text-ink/45">No parameters received.</p>
                @else
                    <dl class="font-mono text-sm">
                        @foreach ($event['query'] as $key => $value)
                            <div class="flex flex-wrap gap-3 py-1">
                                <dt class="w-32 shrink-0 text-ink/45">{{ $key }}</dt>
                                <dd class="break-all">{{ is_array($value) ? json_encode($value) : $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>
        </div>

        @auth
            <p class="mt-8"><a href="/admin/webhook-inbox/{{ $slug }}" class="eyebrow text-ink/40 hover:text-wire">→ Open inbox</a></p>
        @endauth
    </div>
</x-layout>
