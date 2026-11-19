<x-layouts.app title="Kelola dataset - Lookback Bank">
<section class="mx-auto max-w-6xl px-5 py-10"
         x-data="{ stock: @js(old('stock_id', '')), option: @js(old('model_option', 'transfer')) }">

    <h1 class="font-display text-3xl font-extrabold tracking-tight">Kelola dataset OHLCV</h1>
    <p class="mt-2 max-w-prose text-slate-600">
        Perbarui harga terbaru emiten yang ada, atau tambah emiten baru. Unggah CSV hasil yfinance (format mentah maupun bersih).
    </p>

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
                                @if ($m->is_transfer)
                                    <span class="ml-1 rounded-md bg-amber-100 px-2 py-0.5 text-xs font-semibold">transfer dari {{ $m->baseModel?->stock?->ticker ?? '?' }}</span>
                                @endif
                            @else
                                <span class="text-slate-600">Belum ada</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right">
                            <button type="button"
                                    x-on:click="stock = '{{ $s->id }}'; $nextTick(() => $refs.form.scrollIntoView({ behavior: 'smooth' }))"
                                    class="rounded-lg px-3 py-1.5 font-semibold text-harbor transition-all duration-200 hover:bg-white active:scale-95">
                                Update dataset
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ===================== Form unggah ===================== --}}
    @if ($errors->any())
        <div role="alert" class="mt-8 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p class="font-semibold">Dataset belum tersimpan. Periksa hal berikut:</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    <form x-ref="form" method="POST" action="{{ route('admin.datasets.store') }}" enctype="multipart/form-data"
          class="mt-8 space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf

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

        {{-- Emiten baru --}}
        <div x-show="stock === 'new'" x-cloak class="space-y-5 rounded-xl border border-slate-200 bg-paper p-5">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-field label="Ticker (4 huruf, mis. BRIS atau BRIS.JK)" name="new_ticker" maxlength="8" />
                <div class="sm:col-span-2"><x-field label="Nama perusahaan" name="new_name" /></div>
            </div>
            <div class="sm:max-w-xs"><x-field label="Sektor (opsional, bawaan Perbankan)" name="new_sector" /></div>

            <fieldset class="space-y-3">
                <legend class="text-sm font-medium">Model prediksi untuk emiten baru</legend>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors duration-200"
                       :class="option === 'transfer' ? 'border-harbor bg-mist' : 'border-slate-300 bg-white'">
                    <input type="radio" name="model_option" value="transfer" x-model="option" class="mt-1 text-harbor focus:ring-harbor">
                    <span>
                        <span class="block font-semibold">B. Pakai model bank yang sudah ada (transfer)</span>
                        <span class="block text-sm text-slate-600">Cepat, tetapi model itu dilatih untuk saham lain. Scaler dibuat ulang dari harga emiten baru. Akurasinya belum divalidasi dan tidak punya metrik evaluasi.</span>
                    </span>
                </label>
                <div x-show="option === 'transfer'" x-cloak class="pl-8">
                    <label for="transfer_model_id" class="block text-sm font-medium">Model dasar</label>
                    <select id="transfer_model_id" name="transfer_model_id"
                            class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20 sm:max-w-sm">
                        @foreach ($baselines as $b)
                            <option value="{{ $b->id }}" @selected((int) old('transfer_model_id') === $b->id)>{{ $b->stock->ticker }} - {{ $b->lookback_window }} hari</option>
                        @endforeach
                    </select>
                </div>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors duration-200"
                       :class="option === 'upload' ? 'border-harbor bg-mist' : 'border-slate-300 bg-white'">
                    <input type="radio" name="model_option" value="upload" x-model="option" class="mt-1 text-harbor focus:ring-harbor">
                    <span>
                        <span class="block font-semibold">A. Unggah model khusus emiten ini</span>
                        <span class="block text-sm text-slate-600">Pasangan .keras, .joblib, dan .json hasil training di notebook.</span>
                    </span>
                </label>
                <div x-show="option === 'upload'" x-cloak class="space-y-4 pl-8">
                    <div>
                        <label for="lookback_window" class="block text-sm font-medium">Lookback window model</label>
                        <select id="lookback_window" name="lookback_window"
                                class="mt-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                            @foreach ($lookbacks as $lb)
                                <option value="{{ $lb }}" @selected((int) old('lookback_window') === $lb)>{{ $lb }} hari</option>
                            @endforeach
                        </select>
                    </div>
                    @foreach (['model_file' => ['Model (.keras)', '.keras'], 'scaler_file' => ['Scaler (.joblib)', '.joblib'], 'meta_file' => ['Metadata (.json)', '.json']] as $name => [$label, $ext])
                        <div>
                            <label for="{{ $name }}" class="block text-sm font-medium">{{ $label }}</label>
                            <input id="{{ $name }}" type="file" name="{{ $name }}" accept="{{ $ext }}"
                                   class="mt-1.5 block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-mist file:px-4 file:py-2 file:text-sm file:font-semibold file:text-ink hover:file:bg-slate-200 sm:max-w-md">
                        </div>
                    @endforeach
                    <p class="text-sm text-slate-600">File .joblib adalah format pickle yang dijalankan saat dimuat. Hanya unggah file dari notebook Anda sendiri.</p>
                </div>
            </fieldset>
        </div>

        {{-- Berkas CSV --}}
        <div>
            <label for="csv" class="block text-sm font-medium">File CSV harga (yfinance)</label>
            <input id="csv" type="file" name="csv" accept=".csv,text/csv" required
                   class="mt-1.5 block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-mist file:px-4 file:py-2 file:text-sm file:font-semibold file:text-ink hover:file:bg-slate-200 sm:max-w-md">
            <p class="mt-1.5 text-sm text-slate-600">
                Format mentah <code>yf.download().to_csv()</code> (header Price/Ticker/Date) dikenali otomatis, begitu juga CSV bersih
                <code>date,close,high,low,open,volume</code>. Tanggal yang sudah ada akan ditimpa, tanggal baru ditambahkan.
            </p>
        </div>

        <x-button type="submit">Unggah dan perbarui</x-button>
    </form>
</section>
</x-layouts.app>
