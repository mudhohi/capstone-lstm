<x-layouts.app title="Verifikasi akun - Lookback Bank">
<section class="mx-auto flex min-h-[70vh] max-w-md flex-col justify-center px-5 py-16">
    <h1 class="font-display text-3xl font-extrabold tracking-tight">Verifikasi akun</h1>
    <p class="mt-2 text-slate-600">
        Kami mengirim kode 6 digit ke <span class="font-semibold text-ink">{{ $email }}</span>. Kode berlaku 10 menit.
    </p>

    <form method="POST" action="{{ route('verification.otp.verify') }}" class="mt-6 space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        <x-field label="Kode verifikasi" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code"
                 class="text-center text-xl tracking-[0.5em]" required autofocus />
        <x-button type="submit" class="w-full">Verifikasi</x-button>
    </form>

    <div class="mt-6 flex items-center justify-between text-sm">
        <form method="POST" action="{{ route('verification.otp.resend') }}">
            @csrf
            <button type="submit" class="font-semibold text-harbor underline-offset-4 transition-transform duration-150 hover:underline active:scale-95">Kirim ulang kode</button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-slate-600 underline-offset-4 hover:underline">Keluar</button>
        </form>
    </div>
</section>
</x-layouts.app>
