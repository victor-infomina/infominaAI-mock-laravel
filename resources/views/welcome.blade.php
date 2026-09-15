<x-layout>
    <div class="mx-auto max-w-5xl px-6 py-20">
        <div class="mb-16 flex flex-col items-start justify-between gap-10 sm:flex-row sm:items-center">
            <div>
                <p class="eyebrow text-ink/40">Infomina — Internal</p>
                <h1 class="mt-3 max-w-xl font-mono text-4xl font-bold leading-tight sm:text-5xl">
                    A company registry<br>that isn't.
                </h1>
                <p class="mt-5 max-w-md text-sm leading-relaxed text-ink/60">
                    {{ config('app.name') }} stands in for SSM, AsiaVerify, and DNB during
                    development — same request shapes, same status codes, fixture data
                    in place of a live registry.
                </p>
                <div class="mt-8">
                    @auth
                        <a href="{{ route('tokens.index') }}" class="btn-primary">Go to tokens</a>
                    @else
                        <a href="{{ route('login') }}" class="btn-primary">Log in</a>
                    @endauth
                </div>
            </div>
            <div class="specimen-standalone shrink-0">Specimen<br>Not for<br>production</div>
        </div>

        <div class="grid gap-6 sm:grid-cols-3">
            <div class="folder-card mt-8">
                <div class="folder-card__tab">SSM</div>
                <div class="folder-card__body">
                    <p class="mb-3 text-sm text-ink/60">Malaysia company, business & LLP profiles.</p>
                    <ul class="space-y-1 font-mono text-xs text-ink/45">
                        <li>get-search-entity</li>
                        <li>v2/get-company-profile-document</li>
                        <li>get-order-document</li>
                    </ul>
                </div>
            </div>
            <div class="folder-card mt-8">
                <div class="folder-card__tab">AsiaVerify</div>
                <div class="folder-card__body">
                    <p class="mb-3 text-sm text-ink/60">Vietnam, Thailand, China lookups.</p>
                    <ul class="space-y-1 font-mono text-xs text-ink/45">
                        <li>token/create</li>
                        <li>{country}/search</li>
                        <li>{country}/basic</li>
                    </ul>
                </div>
            </div>
            <div class="folder-card mt-8">
                <div class="folder-card__tab">DNB</div>
                <div class="folder-card__body">
                    <p class="mb-3 text-sm text-ink/60">Singapore & Indonesia, routed by product tag.</p>
                    <ul class="space-y-1 font-mono text-xs text-ink/45">
                        <li>POST /dnb</li>
                        <li>XCNS / BCP</li>
                        <li>XICNS / XICDS</li>
                    </ul>
                </div>
            </div>
        </div>

        <footer class="mt-20 eyebrow text-ink/30">
            {{ config('app.name') }} · dev/staging only
        </footer>
    </div>
</x-layout>
