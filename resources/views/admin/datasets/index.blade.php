<x-layouts.app title="Kelola dataset - Lookback Bank">
<section class="mx-auto max-w-6xl px-5 py-10"
         x-data="{
            /* ---- formulir Yahoo Finance ---- */
            symbol: @js(old('symbol', '')),
            fOption: @js(old('model_option', 'general')),
            byTicker: @js($needsModelByTicker),
            today: @js($today),
            start: @js(old('start_date', $defaultStart)),
            end: @js(old('end_date', $today)),
            preset: @js(old('start_date') ? 'custom' : '3y'),
            get symTicker() { return this.symbol.toUpperCase().trim().split('.')[0]; },
            get fShowModel() { return this.symTicker.length === 4 && (! (this.symTicker in this.byTicker) || this.byTicker[this.symTicker]); },
            setPreset(p, years) {
                this.preset = p; this.end = this.today;
                const d = new Date(this.today + 'T00:00:00Z');
                d.setUTCFullYear(d.getUTCFullYear() - years);
                this.start = d.toISOString().slice(0, 10);
            },
            /* ---- formulir CSV (cadangan) ---- */
            stock: @js(old('stock_id', '')),
            option: @js(old('model_option', 'general')),
            needsModel: @js($needsModel),
            get showModel() { return this.stock === 'new' || !! this.needsModel[this.stock]; },
            /* ---- dialog hapus ---- */
            del: null
         }">

    <h1 class="font-display text-3xl font-extrabold tracking-tight">Kelola dataset OHLCV</h1>
    <p class="mt-2 max-w-prose text-slate-600">
        Tarik data harian langsung dari Yahoo Finance cukup dengan kode saham dan rentang tanggal, atau unggah CSV sebagai cadangan saat offline.
    </p>

    @if ($errors->any())
        <div role="alert" class="mt-6 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p class="font-semibold">Tidak berhasil. Periksa hal berikut:</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    {{-- ===================== Status dataset per emiten ===================== --}}
    <div class="mt-8 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="text-slate-600">
                <tr>
                    <th class="px-5 py-3 font-medium">Ticker</th>
                    <th class="px-5 py-3 font-medium">Nama</th>
                    <th class="px-5 py-3 font-medium">Tanggal awal</th>
                    <th class="px-5 py-3 font-medium">Tanggal akhir</th>
                    <th class="px-5 py-3 font-medium">Baris OHLCV</th>
                    <th class="px-5 py-3 font-medium">Model aktif</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($stocks as $s)
                    @php $m = $s->models->first(); @endphp
                    <tr class="border-t border-slate-100 transition-colors duration-200 hover:bg-mist">
                        <td class="px-5 py-3 font-semibold">{{ $s->ticker }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ $s->name }}</td>
                        <td class="px-5 py-3">{{ $s->prices_min_date ?? '-' }}</td>
                        <td class="px-5 py-3">{{ $s->prices_max_date ?? '-' }}</td>
                        <td class="px-5 py-3">{{ number_format($s->prices_count, 0, ',', '.') }}</td>
                        <td class="px-5 py-3">
                            @if ($m)
                                {{ $m->lookback_window }} hari
                                @if ($m->isGeneral())
                                    <span class="ml-1 rounded-md bg-amber-100 px-2 py-0.5 text-xs font-semibold">general</span>
                                @endif
                            @else
                                <span class="text-slate-600">Belum ada</span>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1.5">
                                <form method="POST" action="{{ route('admin.datasets.refresh', $s) }}">
                                    @csrf
                                    <x-button type="submit" variant="ghost" class="!px-3 !py-1.5">Update</x-button>
                                </form>
                                <button type="button"
                                        x-on:click="stock = '{{ $s->id }}'; $refs.csvbox.open = true; $nextTick(() => $refs.csvbox.scrollIntoView({ behavior: 'smooth' }))"
                                        class="btn-fx rounded-lg px-3 py-1.5 font-medium text-slate-600 hover:bg-white">CSV</button>
                                <button type="button"
                                        x-on:click="del = { id: {{ $s->id }}, ticker: '{{ $s->ticker }}', canFull: {{ $s->own_models_count > 0 ? 'false' : 'true' }} }"
                                        class="btn-fx inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-medium text-red-700 hover:bg-red-50">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m5 5v6m4-6v6"/></svg>
                                    Hapus
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-sm text-slate-600">
        Tombol Update menarik data terbaru sampai hari ini dari Yahoo Finance, mundur 7 hari dari tanggal akhir agar nilai sementara hari terakhir ikut dikoreksi.
    </p>

    {{-- ===================== Tarik dari Yahoo Finance ===================== --}}
    <form method="POST" action="{{ route('admin.datasets.fetch') }}"
          class="mt-8 space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        <h2 class="font-display text-xl font-extrabold">Tarik data dari Yahoo Finance</h2>

        <div class="grid gap-5 sm:grid-cols-2">
            <x-field label="Kode saham (mis. ARTO atau ARTO.JK)" name="symbol" x-model="symbol" placeholder="ARTO.JK" autocomplete="off" maxlength="8" required />
            <x-field label="Nama perusahaan (opsional, otomatis dari Yahoo Finance)" name="name" />
        </div>
        <div class="sm:max-w-xs"><x-field label="Sektor (opsional, untuk emiten baru)" name="sector" /></div>

        <div>
            <div class="text-sm font-medium">Rentang tanggal</div>
            <div class="mt-2 flex flex-wrap gap-2" role="group" aria-label="Preset rentang">
                @foreach ([['1y', '1 Tahun Terakhir', 1], ['3y', '3 Tahun Terakhir', 3], ['5y', '5 Tahun Terakhir', 5]] as [$key, $label, $years])
                    <button type="button" x-on:click="setPreset('{{ $key }}', {{ $years }})"
                            :class="preset === '{{ $key }}' ? 'bg-ink text-white' : 'bg-mist text-ink hover:bg-slate-200'"
                            class="btn-fx rounded-lg px-3 py-1.5 text-sm font-semibold">{{ $label }}</button>
                @endforeach
                <button type="button" x-on:click="preset = 'custom'"
                        :class="preset === 'custom' ? 'bg-ink text-white' : 'bg-mist text-ink hover:bg-slate-200'"
                        class="btn-fx rounded-lg px-3 py-1.5 text-sm font-semibold">Custom Rentang</button>
            </div>
            <div class="mt-3 grid gap-5 sm:grid-cols-2 sm:max-w-lg">
                <div>
                    <label for="start_date" class="block text-sm font-medium">Dari tanggal</label>
                    <input id="start_date" type="date" name="start_date" x-model="start" x-on:input="preset = 'custom'" :max="end" required
                           class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm transition-colors duration-200 focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium">Sampai tanggal</label>
                    <input id="end_date" type="date" name="end_date" x-model="end" x-on:input="preset = 'custom'" :max="today" required
                           class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm transition-colors duration-200 focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                </div>
            </div>
            <p class="mt-2 text-sm text-slate-600">Data hari ini bisa belum final bila bursa masih buka. Tarik ulang besok untuk memastikan.</p>
        </div>

        {{-- Model untuk emiten baru / emiten tanpa model aktif --}}
        <fieldset x-show="fShowModel" x-cloak class="space-y-3 rounded-xl border border-slate-200 bg-paper p-5">
            <legend class="px-1 text-sm font-medium">Model prediksi untuk <span x-text="symTicker"></span></legend>
            <label class="btn-fx flex cursor-pointer items-start gap-3 rounded-xl border p-4" :class="fOption === 'general' ? 'border-harbor bg-mist' : 'border-slate-300 bg-white'">
                <input type="radio" name="model_option" value="general" x-model="fOption" :disabled="! fShowModel" class="mt-1 text-harbor focus:ring-harbor">
                <span>
                    <span class="block font-semibold">Pasang model general (siap diprediksi di dashboard)</span>
                    <span class="block text-sm text-slate-600">Model global berbasis log-return. Belum dievaluasi pada emiten ini, jadi metrik belum tersedia.</span>
                </span>
            </label>
            <div x-show="fOption === 'general'" class="pl-8">
                <label for="general_lookback" class="block text-sm font-medium">Lookback window</label>
                <select id="general_lookback" name="general_lookback" :disabled="! fShowModel || fOption !== 'general'"
                        class="mt-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                    @foreach ($lookbacks as $lb)
                        <option value="{{ $lb }}" @disabled(! $generalReady[$lb]) @selected((int) old('general_lookback', 42) === $lb)>
                            {{ $lb }} hari{{ $generalReady[$lb] ? '' : ' (artefak belum ada)' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <label class="btn-fx flex cursor-pointer items-start gap-3 rounded-xl border p-4" :class="fOption === 'none' ? 'border-harbor bg-mist' : 'border-slate-300 bg-white'">
                <input type="radio" name="model_option" value="none" x-model="fOption" :disabled="! fShowModel" class="mt-1 text-harbor focus:ring-harbor">
                <span>
                    <span class="block font-semibold">Belum pasang model</span>
                    <span class="block text-sm text-slate-600">Hanya data harga dan analisa teknikal. Model bisa dipasang nanti lewat menu Kelola model.</span>
                </span>
            </label>
        </fieldset>

        <x-button type="submit">Tarik data</x-button>
    </form>

    {{-- ===================== Unggah CSV (cadangan) ===================== --}}
    <details x-ref="csvbox" class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm" @if (old('csv_form')) open @endif>
        <summary class="cursor-pointer select-none px-6 py-4 font-display text-lg font-extrabold">Unggah CSV (cadangan saat offline)</summary>

        <form x-ref="csvform" method="POST" action="{{ route('admin.datasets.store') }}" enctype="multipart/form-data" class="space-y-6 border-t border-slate-100 p-6">
            @csrf
            <input type="hidden" name="csv_form" value="1">

            <div>
                <label for="stock_id" class="block text-sm font-medium">Emiten</label>
                <select id="stock_id" name="stock_id" x-model="stock" required
                        class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20 sm:max-w-md">
                    <option value="" disabled>Pilih emiten</option>
                    @foreach ($stocks as $s)
                        <option value="{{ $s->id }}">{{ $s->ticker }} - {{ $s->name }}</option>
                    @endforeach
                    <option value="new">+ Tambah emiten baru</option>
                </select>
            </div>

            <div x-show="stock === 'new'" x-cloak class="space-y-5 rounded-xl border border-slate-200 bg-paper p-5">
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-field label="Ticker (4 huruf, mis. BRIS atau BRIS.JK)" name="new_ticker" maxlength="8" />
                    <div class="sm:col-span-2"><x-field label="Nama perusahaan" name="new_name" /></div>
                </div>
                <div class="sm:max-w-xs"><x-field label="Sektor (opsional, bawaan Perbankan)" name="new_sector" /></div>
            </div>

            <fieldset x-show="showModel" x-cloak class="space-y-3 rounded-xl border border-slate-200 bg-paper p-5">
                <legend class="px-1 text-sm font-medium">Model prediksi untuk emiten ini</legend>

                <label class="btn-fx flex cursor-pointer items-start gap-3 rounded-xl border p-4" :class="option === 'general' ? 'border-harbor bg-mist' : 'border-slate-300 bg-white'">
                    <input type="radio" name="model_option" value="general" x-model="option" :disabled="! showModel" class="mt-1 text-harbor focus:ring-harbor">
                    <span><span class="block font-semibold">B. Model general multi-emiten</span>
                    <span class="block text-sm text-slate-600">Belum dievaluasi pada emiten ini, jadi metrik belum tersedia.</span></span>
                </label>
                <div x-show="option === 'general'" class="pl-8">
                    <label for="csv_general_lookback" class="block text-sm font-medium">Lookback window</label>
                    <select id="csv_general_lookback" name="general_lookback" :disabled="! showModel || option !== 'general'"
                            class="mt-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                        @foreach ($lookbacks as $lb)
                            <option value="{{ $lb }}" @disabled(! $generalReady[$lb]) @selected((int) old('general_lookback', 42) === $lb)>
                                {{ $lb }} hari{{ $generalReady[$lb] ? '' : ' (artefak belum ada)' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <label class="btn-fx flex cursor-pointer items-start gap-3 rounded-xl border p-4" :class="option === 'upload' ? 'border-harbor bg-mist' : 'border-slate-300 bg-white'">
                    <input type="radio" name="model_option" value="upload" x-model="option" :disabled="! showModel" class="mt-1 text-harbor focus:ring-harbor">
                    <span><span class="block font-semibold">A. Unggah model khusus emiten ini</span>
                    <span class="block text-sm text-slate-600">Pasangan .keras, .joblib, dan .json hasil training model per emiten (input harga).</span></span>
                </label>
                <div x-show="option === 'upload'" x-cloak class="space-y-4 pl-8">
                    <div>
                        <label for="lookback_window" class="block text-sm font-medium">Lookback window model</label>
                        <select id="lookback_window" name="lookback_window" :disabled="! showModel || option !== 'upload'"
                                class="mt-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                            @foreach ($lookbacks as $lb)
                                <option value="{{ $lb }}" @selected((int) old('lookback_window') === $lb)>{{ $lb }} hari</option>
                            @endforeach
                        </select>
                    </div>
                    @foreach (['model_file' => ['Model (.keras)', '.keras'], 'scaler_file' => ['Scaler (.joblib)', '.joblib'], 'meta_file' => ['Metadata (.json)', '.json']] as $name => [$label, $ext])
                        <div>
                            <label for="{{ $name }}" class="block text-sm font-medium">{{ $label }}</label>
                            <input id="{{ $name }}" type="file" name="{{ $name }}" accept="{{ $ext }}" :disabled="! showModel || option !== 'upload'"
                                   class="mt-1.5 block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-mist file:px-4 file:py-2 file:text-sm file:font-semibold file:text-ink hover:file:bg-slate-200 sm:max-w-md">
                        </div>
                    @endforeach
                    <p class="text-sm text-slate-600">File .joblib adalah pickle yang dijalankan saat dimuat. Hanya unggah file dari notebook Anda sendiri.</p>
                </div>
            </fieldset>

            <div>
                <label for="csv" class="block text-sm font-medium">File CSV harga</label>
                <input id="csv" type="file" name="csv" accept=".csv,text/csv" required
                       class="mt-1.5 block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-mist file:px-4 file:py-2 file:text-sm file:font-semibold file:text-ink hover:file:bg-slate-200 sm:max-w-md">
                <p class="mt-1.5 text-sm text-slate-600">
                    Format mentah <code>yf.download().to_csv()</code> (header Price/Ticker/Date) dikenali otomatis, begitu juga CSV bersih
                    <code>date,close,high,low,open,volume</code>. Tanggal yang sudah ada ditimpa, tanggal baru ditambahkan.
                </p>
            </div>

            <x-button type="submit">Unggah dan perbarui</x-button>
        </form>
    </details>

    {{-- ===================== Dialog konfirmasi hapus ===================== --}}
    <div x-show="del" x-cloak x-transition.opacity.duration.200ms
         x-on:keydown.escape.window="del = null"
         class="fixed inset-0 z-50 flex items-center justify-center bg-ink/50 p-4" role="dialog" aria-modal="true" aria-labelledby="del-title">
        <div x-on:click.outside="del = null" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            <h2 id="del-title" class="font-display text-xl font-extrabold">Hapus dataset <span x-text="del ? del.ticker : ''"></span>?</h2>
            <p class="mt-2 text-sm text-slate-600">
                Yakin ingin menghapus seluruh data historis OHLCV untuk emiten <span class="font-semibold text-ink" x-text="del ? del.ticker : ''"></span>?
                Tindakan ini tidak dapat dibatalkan.
            </p>

            <form method="POST" :action="del ? '{{ url('admin/datasets') }}/' + del.id : '#'" class="mt-4 space-y-4">
                @csrf
                @method('DELETE')
                <label class="flex items-start gap-2 text-sm" :class="del && ! del.canFull ? 'opacity-60' : ''">
                    <input type="checkbox" name="delete_stock" value="1" :disabled="! del || ! del.canFull" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-harbor focus:ring-harbor">
                    <span>Hapus sekalian data emiten dan modelnya dari dashboard (prediksi dan watchlist ikut terhapus)</span>
                </label>
                <p x-show="del && ! del.canFull" x-cloak class="text-xs text-slate-600">
                    Emiten ini punya model khusus beserta hasil evaluasinya, jadi hanya data harganya yang bisa dihapus di sini. Hapus modelnya lewat menu Kelola model bila perlu.
                </p>
                <div class="flex justify-end gap-2">
                    <button type="button" x-on:click="del = null" class="btn-fx rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold hover:border-harbor">Batal</button>
                    <x-button type="submit" variant="danger" class="!px-5 !py-2.5">Hapus</x-button>
                </div>
            </form>
        </div>
    </div>
</section>
</x-layouts.app>
