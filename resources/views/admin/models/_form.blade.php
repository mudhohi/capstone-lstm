{{-- Form bersama tambah/ubah model. Variabel: $action, $stocks, $lookbacks, $generalReady, $model (null saat tambah) --}}
@php
    $isEdit = (bool) $model;
    $isGeneral = $model?->isGeneral() ?? false;
@endphp

@if ($errors->any())
    <div role="alert" class="mt-6 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
        <p class="font-semibold">Data belum tersimpan. Periksa hal berikut:</p>
        <ul class="mt-1 list-inside list-disc">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ $action }}" enctype="multipart/form-data"
      x-data="{ type: @js(old('model_type', $model?->model_type ?? 'price_scaled')) }"
      class="mt-6 space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    @csrf
    @if ($isEdit) @method('PUT') @endif

    {{-- Emiten + lookback: bebas saat tambah, terkunci saat ubah (nama berkas mengikuti keduanya) --}}
    @if ($isEdit)
        <div class="grid gap-5 sm:grid-cols-3">
            <div><div class="text-sm font-medium">Emiten</div><div class="mt-1.5 rounded-xl bg-mist px-4 py-2.5 text-sm font-semibold">{{ $model->stock->ticker }}</div></div>
            <div><div class="text-sm font-medium">Lookback</div><div class="mt-1.5 rounded-xl bg-mist px-4 py-2.5 text-sm font-semibold">{{ $model->lookback_window }} hari</div></div>
            <div><div class="text-sm font-medium">Jenis</div><div class="mt-1.5 rounded-xl bg-mist px-4 py-2.5 text-sm font-semibold">{{ $isGeneral ? 'Model general' : 'Model khusus emiten' }}</div></div>
        </div>
    @else
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="stock_id" class="block text-sm font-medium">Emiten</label>
                <select id="stock_id" name="stock_id" required class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                    @foreach ($stocks as $s)
                        <option value="{{ $s->id }}" @selected((int) old('stock_id') === $s->id)>{{ $s->ticker }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="lookback_window" class="block text-sm font-medium">Lookback window</label>
                <select id="lookback_window" name="lookback_window" required class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                    @foreach ($lookbacks as $lb)
                        <option value="{{ $lb }}" @selected((int) old('lookback_window', 42) === $lb)>{{ $lb }} hari</option>
                    @endforeach
                </select>
            </div>
        </div>

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium">Jenis model</legend>
            <label class="btn-fx flex cursor-pointer items-start gap-3 rounded-xl border p-4" :class="type === 'price_scaled' ? 'border-harbor bg-mist' : 'border-slate-300 bg-white'">
                <input type="radio" name="model_type" value="price_scaled" x-model="type" class="mt-1 text-harbor focus:ring-harbor">
                <span><span class="block font-semibold">Model khusus emiten (unggah berkas)</span>
                <span class="block text-sm text-slate-600">Pasangan .keras/.h5 + .joblib hasil training model per emiten (input harga).</span></span>
            </label>
            <label class="btn-fx flex cursor-pointer items-start gap-3 rounded-xl border p-4" :class="type === 'general_return' ? 'border-harbor bg-mist' : 'border-slate-300 bg-white'">
                <input type="radio" name="model_type" value="general_return" x-model="type" class="mt-1 text-harbor focus:ring-harbor">
                <span><span class="block font-semibold">Model general (pakai artefak yang sudah ada)</span>
                <span class="block text-sm text-slate-600">Tanpa unggahan. Memakai model_general_{lookback} bersama di ml_services/models; berkas bersama tidak pernah ditimpa.
                    Lookback yang artefaknya belum ada akan ditolak.</span></span>
            </label>
        </fieldset>
    @endif

    {{-- Berkas (hanya model khusus emiten) --}}
    @unless ($isGeneral)
        <div x-show="type === 'price_scaled'" class="space-y-4 rounded-xl border border-slate-200 bg-paper p-5">
            @if ($isEdit)
                <p class="text-sm text-slate-600">
                    Berkas saat ini: <span class="font-semibold text-ink">{{ basename($model->file_path) }}</span>.
                    Kosongkan input di bawah untuk mempertahankan berkas lama.
                </p>
            @endif
            @foreach ([
                'model_file'  => ['Model (.keras atau .h5)', '.keras,.h5', ! $isEdit],
                'scaler_file' => ['Scaler (.joblib)', '.joblib', ! $isEdit],
                'meta_file'   => ['Metadata (.json), opsional: metrik dibaca otomatis jika kolom metrik dikosongkan', '.json', false],
            ] as $name => [$label, $accept, $required])
                <div>
                    <label for="{{ $name }}" class="block text-sm font-medium">{{ $label }}</label>
                    <input id="{{ $name }}" type="file" name="{{ $name }}" accept="{{ $accept }}" :disabled="type !== 'price_scaled'" @if ($required) :required="type === 'price_scaled'" @endif
                           class="mt-1.5 block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-mist file:px-4 file:py-2 file:text-sm file:font-semibold file:text-ink hover:file:bg-slate-200 sm:max-w-md">
                </div>
            @endforeach
            <p class="text-sm text-slate-600">Ukuran maksimal 50 MB untuk model. File .joblib adalah pickle: hanya unggah file dari notebook Anda sendiri.</p>
        </div>
    @else
        <p class="rounded-xl bg-mist px-4 py-3 text-sm text-slate-600">Model general memakai berkas bersama ({{ basename($model->file_path) }}), jadi tidak ada berkas yang bisa diganti di sini.</p>
    @endunless

    <p class="text-sm text-slate-600">Metrik opsional. Kosongkan jika belum ada (tampil "-" di dashboard).</p>
    <div class="grid gap-5 sm:grid-cols-3">
        @foreach ([
            'wfv_mae' => 'MAE walk-forward (Rp)', 'wfv_rmse' => 'RMSE walk-forward (Rp)', 'wfv_mape' => 'MAPE walk-forward (%)',
            'holdout_mae' => 'MAE holdout (Rp)', 'holdout_rmse' => 'RMSE holdout (Rp)', 'holdout_mape' => 'MAPE holdout (%)',
        ] as $name => $label)
            <x-field :label="$label" :name="$name" type="number" step="any" min="0" :value="$model?->{$name}" />
        @endforeach
    </div>

    <label class="flex items-center gap-2 text-sm">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $model?->is_active ?? true)) class="h-4 w-4 rounded border-slate-300 text-harbor focus:ring-harbor">
        Model aktif
    </label>

    <div class="flex gap-3">
        <x-button type="submit">{{ $isEdit ? 'Simpan perubahan' : 'Simpan model' }}</x-button>
        <x-button :href="route('admin.models.index')" variant="ghost">Batal</x-button>
    </div>
</form>
