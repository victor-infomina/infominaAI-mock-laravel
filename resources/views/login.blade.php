<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in — SSM Mock</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 text-gray-900">
    <div class="flex min-h-screen items-center justify-center px-6">
        <div class="w-full max-w-sm rounded-md border border-gray-200 bg-white p-8">
            <h1 class="mb-6 text-lg font-semibold">SSM Mock Admin</h1>

            @if ($errors->any())
                <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf
                <div>
                    <label for="email" class="mb-1 block text-sm text-gray-600">Email</label>
                    <input type="email" name="email" id="email" required autofocus
                        class="w-full rounded-md border-gray-300 text-sm shadow-sm" value="{{ old('email') }}">
                </div>
                <div>
                    <label for="password" class="mb-1 block text-sm text-gray-600">Password</label>
                    <input type="password" name="password" id="password" required
                        class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                </div>
                <button type="submit" class="w-full rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
                    Log in
                </button>
            </form>
        </div>
    </div>
</body>
</html>
