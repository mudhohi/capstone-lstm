{{-- Kerangka shimmer untuk kartu metrik, prediksi berikutnya, dan tabel perbandingan --}}
<div aria-busy="true" aria-label="Memuat prediksi">
    <div class="grid gap-4 sm:grid-cols-3">
        @foreach (range(1, 3) as $i)
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <x-skeleton class="h-4 w-24" />
                <x-skeleton class="mt-4 h-8 w-32" />
                <x-skeleton class="mt-3 h-3 w-40" />
            </div>
        @endforeach
    </div>
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5">
        <x-skeleton class="h-5 w-56" />
        @foreach (range(1, 4) as $i)
            <x-skeleton class="mt-4 h-8 w-full" />
        @endforeach
    </div>
</div>
