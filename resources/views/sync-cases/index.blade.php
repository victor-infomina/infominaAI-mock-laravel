<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sync Cases — SSM Mock</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 text-gray-900">
    <div class="mx-auto max-w-4xl px-6 py-10">
        <h1 class="mb-6 text-xl font-semibold">Sync Cases</h1>

        <form method="GET" action="/admin/sync-cases" class="mb-8 flex flex-wrap items-end gap-4">
            <div>
                <label for="q" class="mb-1 block text-sm text-gray-600">Search (name or regNo)</label>
                <input type="text" name="q" id="q" value="{{ $query }}" class="rounded-md border-gray-300 text-sm shadow-sm">
            </div>
            <div>
                <label for="type" class="mb-1 block text-sm text-gray-600">Type</label>
                <select name="type" id="type" class="rounded-md border-gray-300 text-sm shadow-sm">
                    <option value="" @selected(!$type)>Any</option>
                    <option value="company" @selected($type === 'company')>Company</option>
                    <option value="business" @selected($type === 'business')>Business</option>
                    <option value="llp" @selected($type === 'llp')>LLP</option>
                </select>
            </div>
            <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">Search</button>
        </form>

        <table class="w-full overflow-hidden rounded-md border border-gray-200 text-sm">
            <thead class="bg-gray-100 text-left">
                <tr>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Reg No</th>
                    <th class="px-4 py-2">Type</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @forelse ($items as $item)
                    <tr>
                        <td class="px-4 py-2">{{ $item->subject_name }}</td>
                        <td class="px-4 py-2">{{ $item->subject_reg_no }}</td>
                        <td class="px-4 py-2">{{ $item->type }}</td>
                        <td class="px-4 py-2 text-right">
                            <a href="/admin/sync-cases/{{ $item->id }}" class="text-blue-600 hover:text-blue-800">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">No results.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
