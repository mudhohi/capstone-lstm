<x-layouts.app title="Kelola model - Lookback Bank">
<section class="mx-auto max-w-6xl px-5 py-10">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-extrabold tracking-tight">Kelola pre-trained model</h1>
            <p class="mt-2 max-w-prose text-slate-600">Satu baris untuk setiap model terbaik per emiten (4 model aktif).</p>
        </div>
        <x-button :href="route('admin.models.create')">Tambah model</x-button>
    </div>

    <div class="mt-8 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="text-slate-600">
                <tr>
                    <th class="px-5 py-3 font-medium">Saham</th>
                    <th class="px-5 py-3 font-medium">Lookback</th>
                    <th class="px-5 py-3 font-medium">File model</th>
                    <th class="px-5 py-3 font-medium">MAPE walk-forward (%)</th>
                    <th class="px-5 py-3 font-medium">Status</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($models as $m)
                    <tr class="border-t border-slate-100 transition-colors duration-200 hover:bg-mist">
                        <td class="px-5 py-3 font-semibold">{{ $m->stock->ticker }}</td>
                        <td class="px-5 py-3">{{ $m->lookback_window }} hari</td>
                        <td class="px-5 py-3 text-slate-600">{{ $m->file_path }} <span class="text-xs">(.{{ $m->file_format }})</span></td>
                        <td class="px-5 py-3">{{ is_null($m->wfv_mape) ? '-' : number_format($m->wfv_mape, 3, ',', '.') }}</td>
                        <td class="px-5 py-3">{{ $m->is_active ? 'Aktif' : 'Nonaktif' }}{{ $m->isGeneral() ? ' (general)' : ($m->is_transfer ? ' (transfer)' : '') }}</td>
                        <td class="flex justify-end gap-2 px-5 py-3">
                            <a href="{{ route('admin.models.edit', $m) }}" class="rounded-lg px-3 py-1.5 font-medium text-harbor transition-all duration-200 hover:bg-white btn-fx">Ubah</a>
                            <form method="POST" action="{{ route('admin.models.destroy', $m) }}"
                                  x-data x-on:submit="if (! confirm('Hapus model ini beserta seluruh prediksinya?')) $event.preventDefault()">
                                @csrf @method('DELETE')
                                <button type="submit" class="rounded-lg px-3 py-1.5 font-medium text-red-700 transition-all duration-200 hover:bg-red-50 btn-fx">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-8 text-center text-slate-600">Belum ada model. Klik "Tambah model" untuk memulai.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
</x-layouts.app>
