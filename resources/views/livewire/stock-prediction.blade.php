@php
    // Format angka gaya Indonesia; nilai kosong tampil "-" (tidak ada angka rekaan).
    $fmt = fn ($v, $d = 2) => is_null($v) ? '-' : number_format((float) $v, $d, ',', '.');
    $model = $this->models->get($lookback);
    $skeletonTargets = 'lookback,selectStock';
@endphp

{{-- wire:init: jalankan init() segera setelah halaman terbuka --}}
<div wire:init="init" class="mx-auto max-w-6xl px-5 py-10">

    <h1 class="font-display text-3xl font-extrabold tracking-tight">Prediksi harga penutupan</h1>
    <p class="mt-2 max-w-prose text-slate-600">Pilih saham dan lookback window untuk membandingkan harga aktual dengan prediksi LSTM.</p>

    {{-- ===================== Search Stocks (filter) + daftar saham ===================== --}}
    <div class="mt-8">
        <label for="search" class="sr-only">Cari saham</label>
        <input id="search" type="search" wire:model.live.debounce.300ms="search" @disabled(! $ready)
               placeholder="Cari ticker atau nama bank"
               class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm transition-colors duration-200 focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20 sm:max-w-sm">

        <div class="mt-4 flex flex-wrap gap-3">
            @unless ($ready)
                {{-- Skeleton daftar saham saat data belum dimuat --}}
                @foreach (range(1, 4) as $i)
                    <x-skeleton class="h-11 w-28 !rounded-xl" />
                @endforeach
            @else
                @forelse ($this->filteredStocks as $s)
                    <button type="button" wire:key="stock-{{ $s->ticker }}"
                            wire:click="selectStock('{{ $s->ticker }}')"
                            x-on:click="$dispatch('prediction-loading')"
                            @class([
                                'rounded-xl border px-5 py-2.5 text-sm font-semibold transition-all duration-200 active:scale-95',
                                'border-ink bg-ink text-white' => $s->ticker === $ticker,
                                'border-slate-300 bg-white hover:-translate-y-0.5 hover:border-harbor hover:shadow-md' => $s->ticker !== $ticker,
                            ])>
                        {{ $s->ticker }}
                    </button>
                @empty
                    <p class="text-sm text-slate-600">Tidak ada saham yang cocok. Coba BBCA, BBRI, BMRI, atau BBNI.</p>
                @endforelse
            @endunless
        </div>
    </div>

    {{-- ===================== Kontrol: lookback + ekspor ===================== --}}
    <div class="mt-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <label for="lookback" class="block text-sm font-medium">Lookback window</label>
            <select id="lookback" wire:model.live="lookback" x-on:change="$dispatch('prediction-loading')" @disabled(! $ready || count($lookbacks) === 1)
                    class="mt-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm transition-colors duration-200 focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20 disabled:opacity-60">
                @foreach ($lookbacks as $lb)
                    <option value="{{ $lb }}">{{ $lb }} hari perdagangan</option>
                @endforeach
            </select>
            @if ($ready && count($lookbacks) === 1)
                <p class="mt-1.5 text-xs text-slate-600">Lookback terbaik untuk {{ $ticker }} dari hasil eksperimen.</p>
            @endif
        </div>

        @if ($ready)
            <div class="flex gap-2">
                <x-button variant="ghost" :href="route('export.prediction', [$ticker, $lookback, 'csv'])">Unduh CSV</x-button>
                <x-button variant="ghost" :href="route('export.prediction', [$ticker, $lookback, 'pdf'])">Unduh PDF</x-button>
            </div>
        @endif
    </div>

    {{-- ===================== Panel Grafik Utama & Analisa Teknikal (ApexCharts) ===================== --}}
    <div wire:ignore
         x-data="{
            mainChart: null,
            maChart: null,
            chartReady: false,
            empty: false,
            lastSeries: null,
            selectedMAs: [7, 20], // Default aktif lebih dari satu (MA 7 & MA 20)
            maOptions: [
                { period: 7,  label: 'MA 7',  color: '#0E7C86' },
                { period: 14, label: 'MA 14', color: '#2563EB' },
                { period: 20, label: 'MA 20', color: '#E11D48' },
                { period: 50, label: 'MA 50', color: '#7C3AED' }
            ],
            toggleMA(period) {
                if (this.selectedMAs.includes(period)) {
                    this.selectedMAs = this.selectedMAs.filter(p => p !== period);
                } else {
                    this.selectedMAs = [...this.selectedMAs, period].sort((a, b) => a - b);
                }
                this.renderMAChart();
            },
            calcSMA(data, period) {
                return data.map((point, idx, arr) => {
                    if (idx < period - 1) return [point[0], null];
                    const slice = arr.slice(idx - period + 1, idx + 1);
                    const sum = slice.reduce((acc, val) => acc + val[1], 0);
                    return [point[0], sum / period];
                });
            },
            async draw(s) {
                if (!s) return;
                this.lastSeries = s;
                this.empty = s.actual.length === 0;
                if (this.empty) { this.chartReady = true; return; }

                if (this.mainChart) this.mainChart.destroy();
                const rp = (v) => v === null ? '-' : 'Rp ' + Math.round(v).toLocaleString('id-ID');

                // 1. Grafik Utama (Harga Aktual vs Prediksi LSTM)
                this.mainChart = new ApexCharts(this.$refs.mainCanvas, {
                    chart: {
                        id: 'lstm-main-chart',
                        group: 'stock-sync',
                        type: 'line',
                        height: 360,
                        toolbar: { show: true },
                        zoom: { enabled: true },
                        animations: { speed: 400 },
                        fontFamily: 'IBM Plex Sans, sans-serif'
                    },
                    series: [
                        { name: 'Harga aktual', data: s.actual },
                        { name: 'Prediksi LSTM', data: s.predicted }
                    ],
                    colors: ['#0F2A43', '#F2A93B'],
                    stroke: { width: [2, 2], curve: 'straight' },
                    xaxis: { type: 'datetime' },
                    yaxis: { labels: { formatter: rp, minWidth: 80 } },
                    tooltip: { x: { format: 'dd MMM yyyy' }, y: { formatter: rp } },
                    legend: { position: 'top' },
                    annotations: s.holdoutStart ? {
                        xaxis: [{
                            x: s.holdoutStart,
                            borderColor: '#0E7C86',
                            label: { text: 'Final holdout', style: { color: '#fff', background: '#0E7C86' } }
                        }]
                    } : {},
                });

                await this.mainChart.render();
                await this.renderMAChart();
                this.chartReady = true;
            },
            async renderMAChart() {
                if (!this.lastSeries || this.empty) return;
                if (this.maChart) this.maChart.destroy();

                const rp = (v) => v === null ? '-' : 'Rp ' + Math.round(v).toLocaleString('id-ID');

                // Garis dasar harga aktual (tipis/transparan) + garis MA yang dicentang
                const maSeries = [
                    { name: 'Harga penutupan', data: this.lastSeries.actual }
                ];
                const maColors = ['#94A3B8'];
                const maWidths = [1.5];

                this.maOptions.forEach(opt => {
                    if (this.selectedMAs.includes(opt.period)) {
                        maSeries.push({
                            name: opt.label,
                            data: this.calcSMA(this.lastSeries.actual, opt.period)
                        });
                        maColors.push(opt.color);
                        maWidths.push(2);
                    }
                });

                // 2. Grafik Analisa Teknikal (Sub-chart Moving Average di bawah)
                this.maChart = new ApexCharts(this.$refs.maCanvas, {
                    chart: {
                        id: 'lstm-ma-chart',
                        group: 'stock-sync',
                        type: 'line',
                        height: 250,
                        toolbar: { show: false },
                        zoom: { enabled: true },
                        animations: { enabled: false },
                        fontFamily: 'IBM Plex Sans, sans-serif'
                    },
                    series: maSeries,
                    colors: maColors,
                    stroke: { width: maWidths, curve: 'smooth' },
                    xaxis: { type: 'datetime' },
                    yaxis: { labels: { formatter: rp, minWidth: 80 } },
                    tooltip: { x: { format: 'dd MMM yyyy' }, y: { formatter: rp } },
                    legend: { position: 'top', horizontalAlign: 'left' },
                    annotations: this.lastSeries.holdoutStart ? {
                        xaxis: [{
                            x: this.lastSeries.holdoutStart,
                            borderColor: '#0E7C86'
                        }]
                    } : {},
                });

                await this.maChart.render();
            }
         }"
         x-on:prediction-loading.window="chartReady = false"
         x-on:prediction-loaded.window="draw($event.detail.series)"
         class="relative mt-6 space-y-4">

        {{-- Container Grafik Atas: Hasil Prediksi LSTM --}}
        <div class="relative min-h-[380px] rounded-2xl border border-slate-200 bg-white p-4">
            <div class="mb-1 flex items-center justify-between px-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Grafik Prediksi Model LSTM</span>
            </div>
            <div x-ref="mainCanvas"></div>

            <div x-show="! chartReady" x-transition.opacity.duration.200ms class="absolute inset-4 z-10 flex flex-col gap-3 bg-white" aria-busy="true">
                <x-skeleton class="h-6 w-48" />
                <x-skeleton class="w-full flex-1" />
                <x-skeleton class="h-4 w-full" />
            </div>

            <p x-show="empty" x-cloak class="absolute inset-0 z-20 flex items-center justify-center px-8 text-center text-slate-600">
                Belum ada hasil prediksi untuk kombinasi ini. Administrator perlu mengunggah model dan hasil evaluasinya.
            </p>
        </div>

        {{-- Container Grafik Bawah: Analisa Teknikal Moving Average (Multi-Select) --}}
        <div x-show="! empty" class="relative rounded-2xl border border-slate-200 bg-white p-4">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3 px-2">
                <div>
                    <h2 class="text-sm font-bold text-slate-800">Analisa Teknikal — Moving Average (SMA)</h2>
                    <p class="text-xs text-slate-500">Pilih lebih dari satu periode MA untuk melihat sinyal tren dan perpotongan garis (crossover).</p>
                </div>

                {{-- Multi-select tombol toggle periode MA --}}
                <div class="flex flex-wrap items-center gap-2">
                    <template x-for="opt in maOptions" :key="opt.period">
                        <button type="button"
                                @click="toggleMA(opt.period)"
                                :class="selectedMAs.includes(opt.period)
                                    ? 'border-slate-800 bg-slate-900 text-white shadow-sm'
                                    : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'"
                                class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all">
                            <span class="h-2.5 w-2.5 rounded-full" :style="'background-color:' + opt.color"></span>
                            <span x-text="opt.label"></span>
                        </button>
                    </template>
                </div>
            </div>

            <div x-ref="maCanvas"></div>

            <div x-show="! chartReady" class="absolute inset-4 z-10 flex flex-col gap-3 bg-white">
                <x-skeleton class="h-5 w-40" />
                <x-skeleton class="w-full flex-1" />
            </div>
        </div>
    </div>

    {{-- ===================== Metrik + prediksi berikutnya + tabel perbandingan ===================== --}}
    <div class="mt-8">
        @unless ($ready)
            <x-prediction-skeleton />
        @else
            {{-- Skeleton muncul selama request ganti saham/lookback --}}
            <div wire:loading wire:target="{{ $skeletonTargets }}"><x-prediction-skeleton /></div>

            <div wire:loading.remove wire:target="{{ $skeletonTargets }}">
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ([
                        'MAE (Rp)'  => [$model?->wfv_mae,  $model?->holdout_mae,  2],
                        'RMSE (Rp)' => [$model?->wfv_rmse, $model?->holdout_rmse, 2],
                        'MAPE (%)'  => [$model?->wfv_mape, $model?->holdout_mape, 3],
                    ] as $label => [$wfv, $hold, $dec])
                        <div class="rounded-2xl border border-slate-200 bg-white p-5">
                            <div class="text-sm font-medium text-slate-600">{{ $label }}, walk-forward</div>
                            <div class="mt-2 font-display text-3xl font-extrabold">{{ $fmt($wfv, $dec) }}</div>
                            <div class="mt-2 text-sm text-slate-600">Final holdout: {{ $fmt($hold, $dec) }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="text-sm font-medium text-slate-600">Prediksi hari bursa berikutnya ({{ $ticker }}, lookback {{ $lookback }})</div>
                    @if ($this->nextDay)
                        <div class="mt-2 font-display text-3xl font-extrabold">Rp {{ $fmt($this->nextDay['next_close_pred'] ?? null, 0) }}</div>
                        <div class="mt-1 text-sm text-slate-600">Untuk tanggal {{ $this->nextDay['next_date'] ?? '-' }}</div>
                    @else
                        <div class="mt-2 text-slate-600">Layanan prediksi Python belum aktif. Grafik di atas tetap memakai hasil yang tersimpan.</div>
                    @endif
                </div>

                <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                    <table class="w-full text-left text-sm">
                        <caption class="px-5 pt-5 text-left font-display text-lg font-extrabold">{{ count($lookbacks) > 1 ? 'Perbandingan lookback window' : 'Model terbaik' }} untuk {{ $ticker }}</caption>
                        <thead class="text-slate-600">
                            <tr>
                                <th class="px-5 py-3 font-medium">Lookback</th>
                                <th class="px-5 py-3 font-medium">MAE (Rp)</th>
                                <th class="px-5 py-3 font-medium">RMSE (Rp)</th>
                                <th class="px-5 py-3 font-medium">MAPE walk-forward (%)</th>
                                <th class="px-5 py-3 font-medium">MAPE holdout (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lookbacks as $lb)
                                @php $m = $this->models->get($lb); @endphp
                                <tr wire:key="row-{{ $lb }}" @class(['border-t border-slate-100', 'bg-mist font-semibold' => $lb === $lookback])>
                                    <td class="px-5 py-3">
                                        {{ $lb }} hari
                                        @if (count($lookbacks) > 1 && $lb === $this->bestLookback)
                                            <span class="ml-2 rounded-md bg-harbor px-2 py-0.5 text-xs font-semibold text-white">MAPE terendah</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">{{ $fmt($m?->wfv_mae) }}</td>
                                    <td class="px-5 py-3">{{ $fmt($m?->wfv_rmse) }}</td>
                                    <td class="px-5 py-3">{{ $fmt($m?->wfv_mape, 3) }}</td>
                                    <td class="px-5 py-3">{{ $fmt($m?->holdout_mape, 3) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endunless
    </div>
</div>