<x-layouts.app title="Masuk - Lookback Bank">
<section class="mx-auto flex min-h-[70vh] max-w-md flex-col justify-center px-5 py-16">
    <h1 class="font-display text-3xl font-extrabold tracking-tight">Masuk</h1>
    <p class="mt-2 text-slate-600">Masuk untuk melihat prediksi, membandingkan lookback window, dan mengelola watchlist.</p>

    {{-- Display Login/Signup Error --}}
    @if ($errors->any())
        <div role="alert" class="mt-6 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
            Gagal masuk. Periksa kembali email dan kata sandi Anda.
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        <x-field label="Email" name="email" type="email" autocomplete="email" required autofocus />
        <x-field label="Kata sandi" name="password" type="password" autocomplete="current-password" required />

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-slate-300 text-harbor focus:ring-harbor">
            Ingat saya
        </label>

        <x-button type="submit" class="w-full">Masuk</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        Belum punya akun?
        <a href="{{ route('signup') }}" class="font-semibold text-harbor underline-offset-4 hover:underline">Daftar</a>
    </p>
</section>
</x-layouts.app>
