<x-layout title="Sync Cases">
    <div class="mx-auto max-w-4xl px-6 py-12">
        <div class="mb-2 flex items-center gap-3">
            <p class="eyebrow text-ink/40">Registry Search</p>
            <span class="badge-local">Local only</span>
        </div>
        <h1 class="mb-8 font-mono text-2xl font-bold">Sync Cases</h1>

        <div class="folder-card mb-10 mt-8">
            <div class="folder-card__tab">Find an entity</div>
            <div class="folder-card__body">
                <form method="GET" action="/admin/sync-cases" class="flex flex-wrap items-end gap-5">
                    <div class="min-w-[220px] flex-1">
                        <label for="q" class="field-label">Name or reg. no.</label>
                        <input type="text" name="q" id="q" value="{{ $query }}" class="input" placeholder="e.g. Infomina Sdn Bhd">
                    </div>
                    <div>
                        <label for="type" class="field-label">Type</label>
                        <select name="type" id="type" class="input">
                            <option value="" @selected(!$type)>Any</option>
                            <option value="company" @selected($type === 'company')>Company</option>
                            <option value="business" @selected($type === 'business')>Business</option>
                            <option value="llp" @selected($type === 'llp')>LLP</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary">Search</button>
                </form>
            </div>
        </div>

        @if ($query !== '')
            <p class="eyebrow mb-2 text-ink/40">{{ $total }} match{{ $total === 1 ? '' : 'es' }} for "{{ $query }}"</p>
        @endif

        <table class="ledger-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Reg No</th>
                    <th>Type</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td class="font-medium">{{ $item->subject_name }}</td>
                        <td class="font-mono text-xs">{{ $item->subject_reg_no }}</td>
                        <td class="font-mono text-xs uppercase text-ink/55">{{ $item->type }}</td>
                        <td class="text-right"><a href="/admin/sync-cases/{{ $item->id }}" class="eyebrow text-wire hover:text-ink">Open →</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-8 text-center text-ink/40">
                            {{ $query !== '' ? 'No matches.' : 'Search by company name or registration number to begin.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($total > 20)
            <div class="mt-4 flex items-center justify-between">
                <div class="eyebrow">
                    @if ($page > 1)
                        <a class="text-ink/60 hover:text-wire" href="?q={{ urlencode($query) }}&type={{ $type }}&page={{ $page - 1 }}">← Prev</a>
                    @endif
                </div>
                <div class="eyebrow text-ink/40">Page {{ $page }} of {{ (int) ceil($total / 20) }}</div>
                <div class="eyebrow">
                    @if ($page < ceil($total / 20))
                        <a class="text-ink/60 hover:text-wire" href="?q={{ urlencode($query) }}&type={{ $type }}&page={{ $page + 1 }}">Next →</a>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-layout>
