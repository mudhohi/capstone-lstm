@props(['title' => 'Lookback Bank - Prediksi saham perbankan dengan LSTM'])
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-paper font-sans text-ink antialiased">

    {{-- Navigasi: Alpine mengatur menu mobile --}}
    <header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-slate-200 bg-paper/90 backdrop-blur">
        <nav class="mx-auto flex max-w-6xl items-center justify-between px-5 py-3">
            <a href="{{ route('landing') }}" class="font-display text-xl font-extrabold tracking-tight">Lookback Bank</a>

            <div class="hidden items-center gap-1 md:flex">
                <a href="{{ route('landing') }}#tentang" class="rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-200 hover:bg-mist btn-fx">Tentang proyek</a>
                <a href="{{ route('landing') }}#saham" class="rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-200 hover:bg-mist btn-fx">Saham</a>
                <a href="{{ route('landing') }}#fitur" class="rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-200 hover:bg-mist btn-fx">Fitur</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-200 hover:bg-mist btn-fx">Dashboard</a>
                    <a href="{{ route('watchlists.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-200 hover:bg-mist btn-fx">Watchlist</a>
                    @if (auth()->user()->role === 'administrator')
                        <a href="{{ route('admin.models.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-200 hover:bg-mist btn-fx">Kelola model</a>
                        <a href="{{ route('admin.datasets.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-200 hover:bg-mist btn-fx">Kelola dataset</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <x-button type="submit" variant="ghost" class="!px-4 !py-2">Keluar</x-button>
                    </form>
                @else
                    <x-button :href="route('login')" variant="ghost" class="!px-4 !py-2">Masuk</x-button>
                    <x-button :href="route('signup')" class="!px-4 !py-2">Daftar</x-button>
                @endauth
            </div>

            <button type="button" class="rounded-lg p-2 transition-transform duration-150 btn-fx md:hidden"
                    x-on:click="open = !open" :aria-expanded="open" aria-label="Buka menu">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
        </nav>

        <div x-show="open" x-cloak x-transition class="space-y-1 border-t border-slate-200 px-5 py-3 md:hidden">
            <a href="{{ route('landing') }}#tentang" class="block rounded-lg px-3 py-2 text-sm font-medium hover:bg-mist">Tentang proyek</a>
            <a href="{{ route('landing') }}#saham" class="block rounded-lg px-3 py-2 text-sm font-medium hover:bg-mist">Saham</a>
            <a href="{{ route('landing') }}#fitur" class="block rounded-lg px-3 py-2 text-sm font-medium hover:bg-mist">Fitur</a>
            @guest
                <x-button :href="route('login')" variant="ghost" class="w-full">Masuk</x-button>
                <x-button :href="route('signup')" class="w-full">Daftar</x-button>
            @endguest
        </div>
    </header>

    @if (session('status'))
        <div class="mx-auto max-w-6xl px-5 pt-4">
            <div role="status" class="rounded-xl border border-harbor/30 bg-mist px-4 py-3 text-sm font-medium text-ink">{{ session('status') }}</div>
        </div>
    @endif

    <main>{{ $slot }}</main>

    <footer class="mt-24 border-t border-slate-200 py-8 text-sm text-slate-500">
        <div class="mx-auto max-w-6xl px-5">
            Capstone Teknik Informatika, Universitas Mercu Buana. Hasil prediksi bersifat akademik dan bukan saran investasi.
        </div>
    </footer>

    @livewireScripts
</body>
</html>
