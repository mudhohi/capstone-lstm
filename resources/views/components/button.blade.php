{{--
  Tombol animasi. Dipakai sebagai <x-button> (tombol) atau <x-button href="..."> (tautan).
  - hover : sedikit membesar + warna bergeser mulus
  - klik  : active:scale-95 (menekan), lalu kembali saat dilepas
  - motion-reduce menghormati pengaturan "kurangi gerakan" di perangkat
--}}
@props(['href' => null, 'variant' => 'primary', 'type' => 'button'])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-semibold '
          . 'transition-all duration-200 ease-out select-none '
          . 'hover:scale-[1.03] active:scale-95 '
          . 'focus:outline-none focus-visible:ring-4 focus-visible:ring-harbor/30 '
          . 'motion-reduce:transition-none motion-reduce:hover:scale-100 motion-reduce:active:scale-100';

    $variants = [
        'primary'   => 'bg-harbor text-white shadow-md shadow-harbor/25 hover:bg-ink hover:shadow-lg',
        'secondary' => 'bg-signal text-ink hover:bg-amber-400 hover:shadow-lg',
        'ghost'     => 'border border-slate-300 bg-white text-ink hover:border-harbor hover:text-harbor',
    ];
    $classes = $base . ' ' . ($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
