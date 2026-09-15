<x-layout title="Log in">
    <div class="flex min-h-screen flex-col items-center justify-center px-6 py-16">
        <div class="mb-2 text-center">
            <p class="eyebrow text-ink/40">Infomina</p>
            <h1 class="mt-1 font-mono text-lg font-bold uppercase tracking-wide">SSM Mock Gateway</h1>
        </div>

        <div class="folder-card mt-8 w-full max-w-sm">
            <div class="folder-card__tab">Access</div>
            <div class="folder-card__body">
                @if ($errors->any())
                    <div class="notice-error mb-5">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="space-y-5">
                    @csrf
                    <div>
                        <label for="email" class="field-label">Email</label>
                        <input type="email" name="email" id="email" required autofocus class="input" value="{{ old('email') }}">
                    </div>
                    <div>
                        <label for="password" class="field-label">Password</label>
                        <input type="password" name="password" id="password" required class="input">
                    </div>
                    <button type="submit" class="btn-primary w-full">Log in</button>
                </form>
            </div>
        </div>

        <p class="eyebrow mt-8 max-w-sm text-center text-[10px] text-ink/35">
            Specimen credentials only — this gateway never touches production data.
        </p>
    </div>
</x-layout>
