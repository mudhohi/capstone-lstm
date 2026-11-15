{{-- Form bersama untuk tambah dan ubah model. Variabel: $action, $stocks, $lookbacks, $model (null saat tambah) --}}
@php $selected = fn ($key) => old($key, $model?->{$key}); @endphp

@if ($errors->any())
    <div role="alert" class="mt-6 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
        Data belum tersimpan. Periksa isian yang bertanda merah.
    </div>
@endif

<form method="POST" action="{{ $action }}" class="mt-6 space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    @csrf
    @if ($model) @method('PUT') @endif

    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="stock_id" class="block text-sm font-medium">Saham</label>
            <select id="stock_id" name="stock_id" required class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                @foreach ($stocks as $s)
                    <option value="{{ $s->id }}" @selected((int) $selected('stock_id') === $s->id)>{{ $s->ticker }}</option>
                @endforeach
            </select>
            @error('stock_id') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="lookback_window" class="block text-sm font-medium">Lookback window</label>
            <select id="lookback_window" name="lookback_window" required class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                @foreach ($lookbacks as $lb)
                    <option value="{{ $lb }}" @selected((int) $selected('lookback_window') === $lb)>{{ $lb }} hari</option>
                @endforeach
            </select>
            @error('lookback_window') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid gap-5 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <x-field label="Lokasi file model (dibaca layanan Python)" name="file_path" :value="$model?->file_path" required />
        </div>
        <div>
            <label for="file_format" class="block text-sm font-medium">Format</label>
            <select id="file_format" name="file_format" class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                @foreach (['keras', 'h5'] as $f)
                    <option value="{{ $f }}" @selected($selected('file_format') === $f)>.{{ $f }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <p class="text-sm text-slate-600">Metrik diisi dari hasil notebook. Kosongkan jika belum ada.</p>
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
        <x-button type="submit">Simpan</x-button>
        <x-button :href="route('admin.models.index')" variant="ghost">Batal</x-button>
    </div>
</form>
