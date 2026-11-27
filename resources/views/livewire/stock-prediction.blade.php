@php
    // Format angka gaya Indonesia; nilai kosong tampil "-" (tidak ada angka rekaan).
    $fmt = fn ($v, $d = 2) => is_null($v) ? '-' : number_format((float) $v, $d, ',', '.');
    $model = $ready ? $this->models->get($lookback) : null;
    // Proyeksi hanya dihitung setelah grafik tergambar (loadForecast), supaya halaman tidak menunggu API Python.
    $fc = ($ready && $forecastReady) ? $this->forecast : null;
@endphp

{{-- wire:init: jalankan init() segera setelah halaman terbuka --}}
<div wire:init="init" class="mx-auto max-w-6xl px-5 py-10">

    <h1 class="font-display text-3xl font-extrabold tracking-tight">Prediksi harga penutupan</h1>
    <p class="mt-2 max-w-prose text-slate-600">Pilih saham. Model terbaik dan lookback window-nya dipilih otomatis dari hasil eksperimen.</p>

    {{-- ===================== Pilihan saham (4 emiten) ===================== --}}
    <div class="mt-8 flex flex-wrap gap-3">
        @unless ($ready)
            @foreach (range(1, 4) as $i)
                <x-skeleton class="h-11 w-28 !rounded-xl" />
            @endforeach
        @else
            @foreach ($this->stocks as $s)
                <button type="button" wire:key="stock-{{ $s->ticker }}"
                        wire:click="selectStock('{{ $s->ticker }}')"
                        x-on:click="$dispatch('prediction-loading')"
                        @class([
                            'rounded-xl border px-5 py-2.5 text-sm font-semibold transition-all duration-200 btn-fx',
                            'border-ink bg-ink text-white' => $s->ticker === $ticker,
                            'border-slate-300 bg-white hover:-translate-y-0.5 hover:border-harbor hover:shadow-md' => $s->ticker !== $ticker,
                        ])>
                    {{ $s->ticker }}
                </button>
            @endforeach
        @endunless
    </div>

    {{-- ===================== Nama bank + badge model aktif + ekspor ===================== --}}
    <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
        @unless ($ready)
            <x-skeleton class="h-8 w-80" />
        @else
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="font-display text-xl font-extrabold">
                    {{ $this->stock->ticker }}
                    <span class="font-sans text-base font-normal text-slate-600">{{ $this->stock->name }}</span>
                </h2>
                <span class="rounded-full border border-harbor/40 bg-mist px-3 py-1 text-xs font-semibold text-ink">
                    @if ($model)
                        Model aktif: lookback {{ $lookback }} hari perdagangan{{ $model->isGeneral() ? ' (model general)' : ($model->is_transfer ? ' (transfer)' : '') }}
                    @else
                        Belum ada model aktif
                    @endif
                </span>
            </div>
            <div class="flex gap-2">
                <x-button variant="ghost" :href="route('export.prediction', [$ticker, $lookback, 'csv'])">Unduh CSV</x-button>
                <x-button variant="ghost" :href="route('export.prediction', [$ticker, $lookback, 'pdf'])">Unduh PDF</x-button>
            </div>
        @endunless
    </div>

    @if ($ready && $model?->isGeneral())
        <p class="mt-3 rounded-xl border border-signal/50 bg-amber-50 px-4 py-3 text-sm">
            Model general: satu model berbasis log-return yang dilatih pada BBCA, BBNI, BBRI, dan BMRI, lalu dipakai untuk {{ $ticker }}.
            Harga diproyeksikan dari penutupan terakhir dengan e^r. Model ini belum dievaluasi pada {{ $ticker }}, jadi akurasinya belum diketahui.
        </p>
    @elseif ($ready && $model?->is_transfer)
        <p class="mt-3 rounded-xl border border-signal/50 bg-amber-50 px-4 py-3 text-sm">
            Model transfer: prediksi memakai model {{ $this->baseLabel }} dengan scaler yang disesuaikan pada harga {{ $ticker }}.
            Model ini belum dilatih dan divalidasi khusus untuk {{ $ticker }}, jadi akurasinya belum diketahui.
        </p>
    @endif

    {{-- ===================== Grafik LSTM: aktual, prediksi, proyeksi ===================== --}}
    {{-- wire:ignore: Livewire tidak boleh menimpa DOM grafik. Skeleton berada DI ATAS grafik
         dan hilang setelah chart.render() selesai. Garis putus-putus "Proyeksi" diperbarui
         lewat event forecast-updated tanpa menggambar ulang seluruh grafik. --}}
    <div wire:ignore
         x-data="{
            chart: null, chartReady: false, empty: false, base: null, forecast: [],
            seriesList() {
                return [
                    { name: 'Harga aktual', data: this.base.actual },
                    { name: 'Prediksi LSTM', data: this.base.predicted },
                    { name: 'Proyeksi ke depan', data: this.forecast },
                ];
            },
            setForecast(f) {
                this.forecast = f;
                if (this.chart && this.base) this.chart.updateSeries(this.seriesList(), false);
            },
            async draw(s) {
                this.base = s;
                this.empty = s.actual.length === 0;
                if (this.chart) { this.chart.destroy(); this.chart = null; }
                if (this.empty) { this.chartReady = true; this.$wire.loadForecast(); return; }
                const rp = (v) => 'Rp ' + Math.round(v).toLocaleString('id-ID');
                this.chart = new ApexCharts(this.$refs.canvas, {
                    chart: { type: 'line', height: 420, toolbar: { show: true }, zoom: { enabled: true },
                             animations: { speed: 400 }, fontFamily: 'IBM Plex Sans, sans-serif' },
                    series: this.seriesList(),
                    colors: ['#0F2A43', '#F2A93B', '#0E7C86'],
                    stroke: { width: [2, 2, 2.5], dashArray: [0, 0, 6], curve: 'straight' },
                    xaxis: { type: 'datetime' },
                    yaxis: { labels: { formatter: rp } },
                    tooltip: { x: { format: 'dd MMM yyyy' }, y: { formatter: rp } },
                    legend: { position: 'top' },
                    annotations: s.holdoutStart ? { xaxis: [{ x: s.holdoutStart, borderColor: '#0E7C86',
                        label: { text: 'Final holdout', style: { color: '#fff', background: '#0E7C86' } } }] } : {},
                });
                await this.chart.render();
                this.chartReady = true;   // skeleton hilang HANYA setelah grafik benar-benar tergambar
                this.$wire.loadForecast(); // lanjut: minta proyeksi dari layanan Python
            }
         }"
         x-on:prediction-loading.window="chartReady = false; forecast = []"
         x-on:prediction-loaded.window="draw($event.detail.series)"
         x-on:forecast-updated.window="setForecast($event.detail.forecast)"
         class="relative mt-6 min-h-[440px] rounded-2xl border border-slate-200 bg-white p-4">

        <div x-ref="canvas"></div>

        <div x-show="! chartReady" x-transition.opacity.duration.200ms class="absolute inset-4 z-10 flex flex-col gap-3 bg-white" aria-busy="true">
            <x-skeleton class="h-6 w-48" />
            <x-skeleton class="w-full flex-1" />
            <x-skeleton class="h-4 w-full" />
        </div>

        <p x-show="empty" x-cloak class="absolute inset-0 z-20 flex items-center justify-center px-8 text-center text-slate-600">
            Belum ada data harga untuk saham ini. Unggah dataset lewat menu Kelola dataset.
        </p>
    </div>

    {{-- ===================== Prediksi hari bursa ke depan (slider horizon) ===================== --}}
    <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="font-display text-xl font-extrabold">Prediksi hari bursa ke depan</h2>

        <div class="mt-4">
            <label for="horizon" class="block text-sm font-medium">
                Horizon: {{ $horizon }} hari bursa
                @if ($fc)
                    <span class="font-normal text-slate-600">({{ $fc['rows'][0]['date'] }} s.d. {{ $fc['target']['date'] }})</span>
                @endif
            </label>
            <input id="horizon" type="range" min="1" max="20" step="1"
                   wire:model.live.debounce.250ms="horizon" @disabled(! $forecastReady)
                   class="mt-2 w-full accent-harbor disabled:opacity-50">
            <div class="flex justify-between text-xs text-slate-600"><span>1 hari</span><span>20 hari</span></div>
        </div>

        @if (! $ready || ! $forecastReady)
            <div class="mt-5 grid gap-4 sm:grid-cols-3" aria-busy="true">
                @foreach (range(1, 3) as $i)
                    <div class="rounded-xl border border-slate-200 p-4">
                        <x-skeleton class="h-4 w-28" />
                        <x-skeleton class="mt-3 h-8 w-36" />
                        <x-skeleton class="mt-3 h-3 w-24" />
                    </div>
                @endforeach
            </div>
        @elseif ($fc)
            @php
                $up = $fc['delta'] >= 0;
                $tone = $up ? 'text-green-700' : 'text-red-700';
                $sign = $up ? '+' : '-';
            @endphp
            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-sm font-medium text-slate-600">Prediksi hari ke-{{ $horizon }} ({{ $fc['target']['date'] }})</div>
                    <div class="mt-2 font-display text-3xl font-extrabold">Rp {{ $fmt($fc['target']['predicted_close'], 0) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-sm font-medium text-slate-600">Penutupan aktual terakhir ({{ $fc['last']['date'] }})</div>
                    <div class="mt-2 font-display text-3xl font-extrabold">Rp {{ $fmt($fc['last']['close'], 0) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-sm font-medium text-slate-600">Perubahan terhadap penutupan terakhir</div>
                    <div class="mt-2 font-display text-3xl font-extrabold {{ $tone }}">
                        {{ $up ? 'Naik' : 'Turun' }} Rp {{ $fmt(abs($fc['delta']), 0) }}
                    </div>
                    <div class="mt-1 text-sm font-semibold {{ $tone }}">{{ $sign }}{{ $fmt(abs($fc['pct']), 2) }}%</div>
                </div>
            </div>

            <div class="mt-5 max-h-64 overflow-auto rounded-xl border border-slate-200">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-mist text-slate-600">
                        <tr>
                            <th class="px-4 py-2 font-medium">Hari ke-</th>
                            <th class="px-4 py-2 font-medium">Tanggal</th>
                            <th class="px-4 py-2 font-medium">Prediksi (Rp)</th>
                            <th class="px-4 py-2 font-medium">Selisih vs aktual terakhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fc['rows'] as $r)
                            @php $p = $fc['last']['close'] ? ($r['predicted_close'] / $fc['last']['close'] - 1) * 100 : null; @endphp
                            <tr wire:key="fc-{{ $r['step'] }}" class="border-t border-slate-100">
                                <td class="px-4 py-2">{{ $r['step'] }}</td>
                                <td class="px-4 py-2">{{ $r['date'] }}</td>
                                <td class="px-4 py-2">{{ $fmt($r['predicted_close'], 0) }}</td>
                                <td class="px-4 py-2">{{ is_null($p) ? '-' : (($p >= 0 ? '+' : '-') . $fmt(abs($p), 2) . '%') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-sm text-slate-600">
                Prediksi multi-step bersifat rekursif: hasil tiap hari menjadi masukan hari berikutnya, sehingga error menumpuk pada horizon yang panjang.
                Akhir pekan dilewati, libur bursa belum diperhitungkan. Hasil ini bersifat akademik dan bukan saran investasi.
            </p>
        @else
            <p class="mt-5 text-slate-600">Layanan prediksi Python belum aktif atau belum merespons. Grafik di atas tetap memakai hasil yang tersimpan.</p>
        @endif
    </div>

    {{-- ===================== Metrik + tabel model terbaik ===================== --}}
    <div class="mt-8">
        @unless ($ready)
            <x-prediction-skeleton />
        @else
            {{-- Skeleton muncul selama request ganti saham --}}
            <div wire:loading wire:target="selectStock"><x-prediction-skeleton /></div>

            <div wire:loading.remove wire:target="selectStock">
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

                @if ($model && is_null($model->wfv_mape))
                    <p class="mt-3 text-sm text-slate-600">Metrik evaluasi belum tersedia untuk model ini{{ ($model->isGeneral() || $model->is_transfer) ? ' (belum dievaluasi pada emiten ini)' : '' }}.</p>
                @endif

                <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                    <table class="w-full text-left text-sm">
                        <caption class="px-5 pt-5 text-left font-display text-lg font-extrabold">Model terbaik untuk {{ $ticker }}</caption>
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
                            @forelse ($this->models as $lb => $m)
                                <tr wire:key="row-{{ $lb }}" class="border-t border-slate-100 bg-mist font-semibold">
                                    <td class="px-5 py-3">{{ $lb }} hari</td>
                                    <td class="px-5 py-3">{{ $fmt($m->wfv_mae) }}</td>
                                    <td class="px-5 py-3">{{ $fmt($m->wfv_rmse) }}</td>
                                    <td class="px-5 py-3">{{ $fmt($m->wfv_mape, 3) }}</td>
                                    <td class="px-5 py-3">{{ $fmt($m->holdout_mape, 3) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-6 text-center text-slate-600">Belum ada model aktif untuk saham ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endunless
    </div>

    {{-- ===================== Analisa Teknikal ===================== --}}
    {{-- Indikator (SMA, SnR, Volume MA, MACD) dihitung di browser dari data OHLCV: ganti rentang atau
         toggle tidak perlu request ke server. 3 panel disinkronkan lewat chart.group. --}}
    <section wire:ignore
             x-data="{
                rows: [], mode: 'none', ready: false, range: '6M', showSnr: true,
                sma: { 7: false, 14: false, 20: true, 50: true }, charts: [],
                load(p) {
                    this.rows = p.rows; this.mode = p.mode;
                    if (this.mode === 'none') { this.ready = true; return; }
                    this.ready = false;
                    this.$nextTick(() => this.draw());
                },
                async draw() {
                    this.ready = false;
                    this.charts = await window.TA.draw(this.$refs,
                        { rows: this.rows, mode: this.mode, range: this.range, sma: this.sma, showSnr: this.showSnr }, this.charts);
                    this.ready = true;
                }
             }"
             x-on:prediction-loading.window="ready = false"
             x-on:technical-loaded.window="load($event.detail.payload)"
             class="mt-12">

        <h2 class="font-display text-2xl font-extrabold tracking-tight">Analisa teknikal</h2>

        <div x-show="mode !== 'none'" x-cloak class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-3">
            <div class="flex gap-1.5" role="group" aria-label="Rentang waktu">
                <template x-for="r in ['3M', '6M', '1Y', 'All']" :key="r">
                    <button type="button" x-on:click="range = r; draw()" x-text="r"
                            :class="range === r ? 'bg-ink text-white' : 'bg-mist text-ink hover:bg-slate-200'"
                            class="rounded-lg px-3 py-1.5 text-sm font-semibold transition-all duration-200 btn-fx"></button>
                </template>
            </div>
            <div class="flex flex-wrap gap-1.5" role="group" aria-label="Garis SMA">
                <template x-for="n in [7, 14, 20, 50]" :key="n">
                    <label :class="sma[n] ? 'border-harbor bg-mist' : 'border-slate-300 bg-white'"
                           class="inline-flex cursor-pointer items-center rounded-lg border px-3 py-1.5 text-sm font-medium transition-all duration-200 focus-within:ring-4 focus-within:ring-harbor/30 btn-fx">
                        <input type="checkbox" class="sr-only" x-model="sma[n]" x-on:change="draw()">
                        <span x-text="'SMA ' + n"></span>
                    </label>
                </template>
            </div>
            <label :class="showSnr ? 'border-harbor bg-mist' : 'border-slate-300 bg-white'"
                   class="inline-flex cursor-pointer items-center rounded-lg border px-3 py-1.5 text-sm font-medium transition-all duration-200 focus-within:ring-4 focus-within:ring-harbor/30 btn-fx">
                <input type="checkbox" class="sr-only" x-model="showSnr" x-on:change="draw()">
                Tampilkan SnR
            </label>
        </div>

        <p x-show="mode === 'close_only'" x-cloak class="mt-3 rounded-xl border border-signal/50 bg-amber-50 px-4 py-3 text-sm">
            Mode terbatas: hanya harga penutupan dari hasil impor prediksi, tanpa candlestick dan volume.
            Jalankan php artisan lstm:import-ohlcv untuk tampilan lengkap.
        </p>

        <div class="relative mt-4 min-h-[300px] rounded-2xl border border-slate-200 bg-white p-4">
            <p x-show="mode === 'none' && ready" x-cloak class="py-16 text-center text-slate-600">
                Data harga belum tersedia untuk saham ini. Jalankan php artisan lstm:import-results lalu php artisan lstm:import-ohlcv.
            </p>

            <div x-show="mode !== 'none'">
                <div x-ref="price"></div>
                <div x-ref="volume" x-show="mode === 'ohlcv'"></div>
                <div x-ref="macd"></div>
            </div>

            <div x-show="! ready" x-transition.opacity.duration.200ms class="absolute inset-4 z-10 flex flex-col gap-3 bg-white" aria-busy="true">
                <x-skeleton class="h-[300px] w-full" />
                <x-skeleton class="h-[100px] w-full" />
                <x-skeleton class="h-[140px] w-full" />
            </div>
        </div>
    </section>
</div>
