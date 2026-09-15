<x-layout :title="$row->subject_name">
    <div class="mx-auto max-w-3xl px-6 py-12">
        <a href="/admin/sync-cases" class="eyebrow text-ink/40 hover:text-wire">← Back to search</a>

        <div class="folder-card relative mb-10 mt-8">
            <div class="folder-card__tab">Case dossier · #{{ $requestId }}</div>
            <span class="specimen">Specimen</span>
            <div class="folder-card__body">
                <h1 class="font-mono text-xl font-bold">{{ $row->subject_name }}</h1>
                <p class="mt-1 font-mono text-sm text-ink/55">{{ $row->subject_reg_no }} · <span class="uppercase">{{ $row->type }}</span></p>
            </div>
        </div>

        @if ($syncResult)
            <div class="notice-info mb-8">{{ $syncResult }}</div>
        @endif

        <div class="folder-card mt-8">
            <div class="folder-card__tab">Exhibits — Idaman documents</div>
            <div class="folder-card__body">
                <form method="POST" action="/admin/sync-cases/{{ $requestId }}/sync">
                    @csrf
                    @if (count($idamanDocuments) > 0)
                        <label class="mb-3 flex items-center gap-2 text-sm text-ink/70">
                            <input type="checkbox" class="accent-wire" onclick="document.querySelectorAll('.idaman-checkbox').forEach(c => c.checked = this.checked)">
                            Select all ({{ count($idamanDocuments) }})
                        </label>
                        <ul class="mb-6 divide-y divide-rule border border-rule">
                            @foreach ($idamanDocuments as $doc)
                                <li class="flex items-center gap-3 px-3 py-2 text-sm">
                                    <label class="flex flex-1 items-center gap-3">
                                        <input type="checkbox" class="idaman-checkbox accent-wire" name="idaman_version_ids[]" value="{{ $doc->version_id }}">
                                        <span class="font-mono text-xs text-ink/45">{{ $doc->version_id }}</span>
                                        <span>Form {{ $doc->form }}</span>
                                        <span class="ml-auto text-ink/45">{{ $doc->document_date }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mb-6 text-sm text-ink/45">No Idaman documents available for this entity.</p>
                    @endif

                    <button type="submit" class="btn-primary">Sync to shared host</button>
                </form>
            </div>
        </div>
    </div>
</x-layout>
