<x-layouts.app title="Daftar - Lookback Bank">
<section class="mx-auto flex min-h-[70vh] max-w-md flex-col justify-center px-5 py-16">
    <h1 class="font-display text-3xl font-extrabold tracking-tight">Buat akun</h1>
    <p class="mt-2 text-slate-600">Setelah mendaftar, kode verifikasi 6 digit akan dikirim ke email Anda.</p>

    {{-- Display Login/Signup Error --}}
    @if ($errors->any())
        <div role="alert" class="mt-6 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
            Pendaftaran belum berhasil. Periksa isian yang bertanda merah.
        </div>
    @endif

    <form method="POST" action="{{ route('signup') }}" class="mt-6 space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        <x-field label="Nama" name="name" autocomplete="name" required autofocus />
        <x-field label="Email" name="email" type="email" autocomplete="email" required />
        <x-field label="Kata sandi (minimal 8 karakter)" name="password" type="password" autocomplete="new-password" required />
        <x-field label="Ulangi kata sandi" name="password_confirmation" type="password" autocomplete="new-password" required />

        <x-button type="submit" class="w-full">Daftar</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        Sudah punya akun?
        <a href="{{ route('login') }}" class="font-semibold text-harbor underline-offset-4 hover:underline">Masuk</a>
    </p>
</section>
</x-layouts.app>
