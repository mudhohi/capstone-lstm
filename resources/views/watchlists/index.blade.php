<x-layouts.app title="Watchlist - Lookback Bank">
<section class="mx-auto max-w-6xl px-5 py-10">
    <h1 class="font-display text-3xl font-extrabold tracking-tight">Watchlist</h1>
    <p class="mt-2 max-w-prose text-slate-600">Saham yang Anda pantau. Klik kartu untuk membuka prediksinya.</p>

    @if ($available->isNotEmpty())
        <form method="POST" action="{{ route('watchlists.store') }}" class="mt-6 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="stock_id" class="block text-sm font-medium">Tambah saham</label>
                <select id="stock_id" name="stock_id" required
                        class="mt-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20">
                    @foreach ($available as $s)
                        <option value="{{ $s->id }}">{{ $s->ticker }} - {{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <x-button type="submit">Tambahkan</x-button>
        </form>
    @endif

    <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        @forelse ($items as $item)
            <x-card>
                <div class="font-display text-3xl font-extrabold tracking-tight transition-colors duration-300 group-hover:text-harbor">{{ $item->stock->ticker }}</div>
                <div class="mt-1 text-sm text-slate-600">{{ $item->stock->name }}</div>
                <div class="mt-5 flex items-center justify-between gap-2">
                    <x-button :href="route('dashboard', $item->stock->ticker)" class="!px-4 !py-2">Lihat prediksi</x-button>
                    <form method="POST" action="{{ route('watchlists.destroy', $item->stock_id) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition-all duration-200 hover:bg-red-50 hover:text-red-700 btn-fx">Hapus</button>
                    </form>
                </div>
            </x-card>
        @empty
            <p class="text-slate-600 sm:col-span-2 lg:col-span-4">Watchlist masih kosong. Tambahkan saham dari pilihan di atas.</p>
        @endforelse
    </div>
</section>
</x-layouts.app>
