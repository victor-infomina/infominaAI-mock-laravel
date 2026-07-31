<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Tokens — SSM Mock</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 text-gray-900">
    <div class="mx-auto max-w-4xl px-6 py-10">
        <div class="mb-8 flex items-center justify-between">
            <h1 class="text-xl font-semibold">API Tokens</h1>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-gray-500 hover:text-gray-800">Log out</button>
            </form>
        </div>

        @if (session('generatedSecret'))
            <div class="mb-8 rounded-md border border-amber-300 bg-amber-50 p-4">
                <p class="text-sm font-medium text-amber-800">Token created. Copy the secret now — it will not be shown again.</p>
                <dl class="mt-3 space-y-1 text-sm">
                    <div><dt class="inline font-medium">Key:</dt> <dd class="inline font-mono">{{ session('generatedKey') }}</dd></div>
                    <div><dt class="inline font-medium">Secret:</dt> <dd class="inline font-mono">{{ session('generatedSecret') }}</dd></div>
                </dl>
            </div>
        @endif

        <div class="mb-10 rounded-md border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-sm font-semibold">Generate a new token</h2>
            <form method="POST" action="{{ route('tokens.store') }}" class="flex flex-wrap items-end gap-4">
                @csrf
                <div>
                    <label for="label" class="mb-1 block text-sm text-gray-600">Label</label>
                    <input type="text" name="label" id="label" required
                        class="rounded-md border-gray-300 text-sm shadow-sm" value="{{ old('label') }}">
                </div>
                <div>
                    <label for="purpose" class="mb-1 block text-sm text-gray-600">Purpose</label>
                    <select name="purpose" id="purpose" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="gateway">gateway (infominaAI-BE)</option>
                        <option value="admin_sync">admin_sync (local case-sync tool)</option>
                    </select>
                </div>
                <div>
                    <label for="expires_in_days" class="mb-1 block text-sm text-gray-600">Expires</label>
                    <select name="expires_in_days" id="expires_in_days" class="rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="30">30 days</option>
                        <option value="90">90 days</option>
                        <option value="180">180 days</option>
                        <option value="365">365 days</option>
                        <option value="">No expiry</option>
                    </select>
                </div>
                <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
                    Generate
                </button>
            </form>
            @error('label')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <table class="w-full overflow-hidden rounded-md border border-gray-200 text-sm">
            <thead class="bg-gray-100 text-left">
                <tr>
                    <th class="px-4 py-2">Label</th>
                    <th class="px-4 py-2">Purpose</th>
                    <th class="px-4 py-2">Key</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Expires</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @forelse ($apiClients as $apiClient)
                    <tr>
                        <td class="px-4 py-2">{{ $apiClient->label }}</td>
                        <td class="px-4 py-2">{{ $apiClient->purpose }}</td>
                        <td class="px-4 py-2 font-mono">{{ $apiClient->key }}</td>
                        <td class="px-4 py-2">
                            @if ($apiClient->isRevoked())
                                <span class="text-gray-500">Revoked</span>
                            @elseif ($apiClient->isExpired())
                                <span class="text-red-600">Expired</span>
                            @else
                                <span class="text-green-600">Active</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $apiClient->expires_at?->toDateString() ?? 'Never' }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($apiClient->isActive())
                                <form method="POST" action="{{ route('tokens.revoke', $apiClient) }}">
                                    @csrf
                                    <button type="submit" class="text-red-600 hover:text-red-800">Revoke</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-gray-500">No tokens yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
