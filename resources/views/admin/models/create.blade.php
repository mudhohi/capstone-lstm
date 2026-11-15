<x-layouts.app title="Tambah model - Lookback Bank">
<section class="mx-auto max-w-3xl px-5 py-10">
    <h1 class="font-display text-3xl font-extrabold tracking-tight">Tambah model</h1>
    @include('admin.models._form', ['action' => route('admin.models.store'), 'model' => null])
</section>
</x-layouts.app>
