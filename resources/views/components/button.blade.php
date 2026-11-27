{{--
  Tombol animasi: <x-button> (tombol) atau <x-button href="..."> (tautan).
  - btn-fx : terangkat saat hover (+ bayangan), tertekan saat diklik (lihat resources/css/app.css)
  - type="submit": otomatis menampilkan spinner berputar sesesaat setelah klik, selama form diproses
    (menarik data yfinance, mengunggah model .keras, dst.). Spinner tidak muncul bila form tidak valid.
--}}
@props(['href' => null, 'variant' => 'primary', 'type' => 'button'])

@php
    $base = 'btn-fx inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-semibold select-none '
          . 'focus:outline-none focus-visible:ring-4 focus-visible:ring-harbor/30';

    $variants = [
        'primary'   => 'bg-harbor text-white shadow-md shadow-harbor/25 hover:bg-ink hover:shadow-lg',
        'secondary' => 'bg-signal text-ink hover:bg-amber-400 hover:shadow-lg',
        'ghost'     => 'border border-slate-300 bg-white text-ink hover:border-harbor hover:text-harbor',
        'danger'    => 'bg-red-700 text-white hover:bg-red-800 hover:shadow-lg',
    ];
    $classes = $base . ' ' . ($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@elseif ($type === 'submit')
    {{-- busy diaktifkan setelah klik (setTimeout) agar pengiriman form tidak terganggu --}}
    <button type="submit"
            x-data="{ busy: false }"
            x-on:click="setTimeout(() => { if ($el.form && $el.form.checkValidity()) busy = true }, 0)"
            x-on:pageshow.window="busy = false"
            :class="busy && 'pointer-events-none opacity-80'"
            :aria-busy="busy"
            {{ $attributes->merge(['class' => $classes]) }}>
        <svg x-show="busy" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        {{ $slot }}
    </button>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
