<x-layouts.app>

{{-- ============================ 1. HERO ============================ --}}
<section class="mx-auto grid max-w-6xl items-center gap-12 px-5 pb-20 pt-16 lg:grid-cols-2 lg:pt-24">
    <div>
        <h1 class="font-display text-4xl font-extrabold leading-[1.08] tracking-tight sm:text-5xl">
            Seberapa jauh LSTM perlu menengok ke belakang untuk menebak harga saham bank?
        </h1>
        <p class="mt-6 max-w-prose text-lg leading-relaxed text-slate-600">
            Bandingkan lookback window 21, 42, 63, dan 126 hari pada BBCA, BBRI, BMRI, dan BBNI.
            Setiap hasil dilengkapi MAE, RMSE, dan MAPE dari walk-forward validation.
        </p>
        <div class="mt-9 flex flex-wrap gap-3">
            <x-button :href="route('dashboard')">Jelajahi Dashboard</x-button>
            <x-button href="#tentang" variant="ghost">Pelajari proyek</x-button>
        </div>
    </div>

    {{-- Ilustrasi interaktif: pilih panjang jendela, area teal melebar/menyempit --}}
    <div x-data="{ w: 42 }" class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Pilih lookback window">
            <template x-for="n in [21, 42, 63, 126]" :key="n">
                <button type="button" x-on:click="w = n" x-text="n + ' hari'"
                        :class="w === n ? 'bg-ink text-white' : 'bg-mist text-ink hover:bg-slate-200'"
                        class="rounded-lg px-3 py-1.5 text-sm font-semibold transition-all duration-200 btn-fx"></button>
            </template>
        </div>

        <svg viewBox="0 0 480 220" class="mt-4 w-full" role="img"
             aria-label="Ilustrasi jendela lookback pada deret harga">
            {{-- jendela lookback: lebar mengikuti pilihan --}}
            <rect :x="440 - (w / 126) * 400" y="16" :width="(w / 126) * 400" height="188" rx="10"
                  fill="#0E7C86" fill-opacity="0.14" stroke="#0E7C86" stroke-opacity="0.5"
                  class="transition-all duration-500 ease-out"/>
            {{-- deret harga skematis (ilustrasi, bukan data pasar) --}}
            <path d="M10 150 C50 120 70 170 110 140 S180 90 220 120 S290 170 330 110 S400 60 440 90"
                  fill="none" stroke="#0F2A43" stroke-width="3" stroke-linecap="round"/>
            {{-- titik yang ditebak model: harga penutupan berikutnya --}}
            <circle cx="456" cy="76" r="7" fill="#F2A93B"/>
            <line x1="440" y1="90" x2="456" y2="76" stroke="#F2A93B" stroke-width="2" stroke-dasharray="3 3"/>
        </svg>

        <p class="mt-2 text-sm text-slate-600">
            Model membaca <span class="font-semibold" x-text="w"></span> hari terakhir (area teal)
            untuk menebak harga penutupan hari berikutnya (titik amber). Ilustrasi konsep, bukan data pasar.
        </p>
    </div>
</section>

{{-- ========================= 2. ABOUT PROJECT ========================= --}}
<section id="tentang" class="scroll-mt-20 bg-mist py-20">
    <div class="mx-auto max-w-6xl px-5">
        <h2 class="font-display text-3xl font-extrabold tracking-tight">Tentang proyek</h2>
        <p class="mt-4 max-w-prose leading-relaxed text-slate-600">
            Capstone ini menguji pengaruh panjang lookback window terhadap kinerja LSTM
            dalam memprediksi harga penutupan saham sektor perbankan Indonesia.
        </p>

        <div class="mt-10 grid gap-6 md:grid-cols-3">
            <x-card>
                <h3 class="font-display text-xl font-extrabold">Apa itu LSTM</h3>
                <p class="mt-3 leading-relaxed text-slate-600">
                    Jaringan saraf yang punya sel memori, sehingga bisa memilih informasi harga
                    lama mana yang masih relevan untuk menebak harga berikutnya.
                </p>
            </x-card>
            <x-card>
                <h3 class="font-display text-xl font-extrabold">Arsitektur microservice</h3>
                <p class="mt-3 leading-relaxed text-slate-600">
                    Layanan Python (FastAPI) menjalankan model tersimpan. Laravel dan MySQL
                    mengambil hasilnya lewat API lalu menampilkannya di dashboard.
                </p>
            </x-card>
            <x-card>
                <h3 class="font-display text-xl font-extrabold">Pengaruh lookback window</h3>
                <p class="mt-3 leading-relaxed text-slate-600">
                    Jendela pendek peka terhadap perubahan terbaru, jendela panjang membawa
                    lebih banyak konteks. Dashboard membandingkan keduanya dengan metrik yang sama.
                </p>
            </x-card>
        </div>
    </div>
</section>

{{-- ======================== 3. SUPPORTED STOCKS ======================== --}}
<section id="saham" class="mx-auto max-w-6xl scroll-mt-20 px-5 py-20">
    <h2 class="font-display text-3xl font-extrabold tracking-tight">Saham yang didukung</h2>
    <p class="mt-4 max-w-prose leading-relaxed text-slate-600">Empat emiten perbankan dengan data harian yang sama panjang.</p>

    <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            'BBCA' => 'Bank Central Asia',
            'BBRI' => 'Bank Rakyat Indonesia',
            'BMRI' => 'Bank Mandiri',
            'BBNI' => 'Bank Negara Indonesia',
        ] as $ticker => $name)
            <x-card :href="route('dashboard', $ticker)">
                <div class="font-display text-4xl font-extrabold tracking-tight transition-colors duration-300 group-hover:text-harbor">{{ $ticker }}</div>
                <div class="mt-2 text-slate-600">{{ $name }}</div>
            </x-card>
        @endforeach
    </div>
</section>

{{-- ======================= 4. FEATURE HIGHLIGHTS ======================= --}}
<section id="fitur" class="scroll-mt-20 bg-ink py-20 text-white">
    <div class="mx-auto max-w-6xl px-5">
        <h2 class="font-display text-3xl font-extrabold tracking-tight">Yang membuat hasilnya bisa dipercaya</h2>

        <div class="mt-10 grid gap-6 md:grid-cols-2">
            <x-card class="!border-white/15 !bg-white/5 hover:!border-signal">
                <h3 class="font-display text-xl font-extrabold">Walk-forward validation</h3>
                <p class="mt-3 leading-relaxed text-slate-300">
                    Tiga fold expanding window menjaga urutan waktu. Dua puluh persen data terakhir
                    disimpan sebagai final holdout dan hanya dipakai sekali.
                </p>
            </x-card>
            <x-card class="!border-white/15 !bg-white/5 hover:!border-signal">
                <h3 class="font-display text-xl font-extrabold">Metrik yang terbuka</h3>
                <p class="mt-3 leading-relaxed text-slate-300">
                    MAE, RMSE, dan MAPE tampil untuk setiap kombinasi saham dan lookback,
                    langsung dari hasil evaluasi, tanpa angka rekaan.
                </p>
            </x-card>
            <x-card class="!border-white/15 !bg-white/5 hover:!border-signal">
                <h3 class="font-display text-xl font-extrabold">Ekspor CSV dan PDF</h3>
                <p class="mt-3 leading-relaxed text-slate-300">
                    Unduh hasil prediksi dan metrik untuk dianalisis ulang atau dilampirkan di laporan.
                </p>
            </x-card>
            <x-card class="!border-white/15 !bg-white/5 hover:!border-signal">
                <h3 class="font-display text-xl font-extrabold">Watchlist pribadi</h3>
                <p class="mt-3 leading-relaxed text-slate-300">
                    Simpan saham yang ingin dipantau supaya perbandingan lookback berikutnya
                    bisa dibuka dengan satu klik.
                </p>
            </x-card>
        </div>

        <div class="mt-12 flex flex-wrap items-center gap-4">
            <x-button :href="route('dashboard')" variant="secondary">Jelajahi Dashboard</x-button>
            <span class="text-slate-300">Masuk atau daftar akan diminta jika belum login.</span>
        </div>
    </div>
</section>

</x-layouts.app>
