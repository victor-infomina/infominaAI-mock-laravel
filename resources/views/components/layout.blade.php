@props(['title' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' — '.config('app.name') : config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-ledger font-sans text-ink antialiased">
    @auth
        <header class="border-b border-rule bg-paper">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <a href="/tokens" class="eyebrow text-ink hover:text-wire">Infomina — SSM Mock Gateway</a>
                <nav class="flex items-center gap-6">
                    <a href="{{ route('tokens.index') }}" class="eyebrow {{ request()->routeIs('tokens.*') ? 'text-wire' : 'text-ink/50 hover:text-ink' }}">Tokens</a>
                    @if (app()->environment('local'))
                        <a href="/admin/sync-cases" class="eyebrow {{ request()->is('admin/sync-cases*') ? 'text-wire' : 'text-ink/50 hover:text-ink' }}">Sync Cases</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="eyebrow text-ink/50 hover:text-stamp">Log out</button>
                    </form>
                </nav>
            </div>
        </header>
    @endauth

    <main>
        {{ $slot }}
    </main>
</body>
</html>
