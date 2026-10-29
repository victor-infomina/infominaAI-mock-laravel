<x-layout :title="'Event · '.$event['id']">
    <div class="mx-auto max-w-4xl px-6 py-12">
        <a href="{{ route('webhook-inbox.show', $slug) }}" class="eyebrow text-ink/40 hover:text-wire">← Back to inbox {{ $slug }}</a>

        <div class="folder-card relative mb-10 mt-8">
            <div class="folder-card__tab">{{ $event['kind'] ?? 'event' }} · {{ $event['event_type'] ?? '' }}</div>
            <div class="folder-card__body">
                <h1 class="font-mono text-xl font-bold">{{ $event['reference_id'] ?? 'no referenceId' }}</h1>
                <p class="mt-1 font-mono text-sm text-ink/55">{{ $event['received_at'] ?? '' }} · hash {{ $event['hash_status'] ?? 'unchecked' }} · responded {{ $event['response_status'] ?? '—' }}</p>
            </div>
        </div>

        <div class="folder-card">
            <div class="folder-card__tab">Stored record</div>
            <div class="folder-card__body">
                <pre class="overflow-x-auto font-mono text-xs leading-relaxed">{{ $json }}</pre>
            </div>
        </div>
    </div>
</x-layout>
