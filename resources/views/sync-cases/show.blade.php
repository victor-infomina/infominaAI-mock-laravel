<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $row->subject_name }} — Sync Cases — SSM Mock</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 text-gray-900">
    <div class="mx-auto max-w-3xl px-6 py-10">
        <a href="/admin/sync-cases" class="text-sm text-gray-500 hover:text-gray-800">&larr; Back to search</a>
        <h1 class="mb-1 mt-2 text-xl font-semibold">{{ $row->subject_name }}</h1>
        <p class="mb-6 text-sm text-gray-500">{{ $row->subject_reg_no }} — {{ $row->type }}</p>

        @if ($syncResult)
            <div class="mb-6 rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">{{ $syncResult }}</div>
        @endif

        <form method="POST" action="/admin/sync-cases/{{ $requestId }}/sync">
            @csrf
            <h2 class="mb-2 text-sm font-semibold">Idaman documents</h2>
            @if (count($idamanDocuments) > 0)
                <label class="mb-2 block text-sm">
                    <input type="checkbox" onclick="document.querySelectorAll('.idaman-checkbox').forEach(c => c.checked = this.checked)">
                    Select all
                </label>
                <ul class="mb-4 space-y-1">
                    @foreach ($idamanDocuments as $doc)
                        <li class="text-sm">
                            <label>
                                <input type="checkbox" class="idaman-checkbox" name="idaman_version_ids[]" value="{{ $doc->version_id }}">
                                {{ $doc->version_id }} — Form {{ $doc->form }} ({{ $doc->document_date }})
                            </label>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mb-4 text-sm text-gray-500">No Idaman documents available for this entity.</p>
            @endif

            <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
                Sync to shared host
            </button>
        </form>
    </div>
</body>
</html>
