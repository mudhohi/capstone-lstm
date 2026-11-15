{{--
  Kartu dengan efek hover: terangkat (-translate-y), bayangan membesar,
  border berubah ke teal. Dipakai di Landing Page dan halaman Watchlists.
  Beri atribut href untuk menjadikan seluruh kartu sebagai tautan.
--}}
@props(['href' => null])

@php
    $classes = 'group block rounded-2xl border border-slate-200 bg-white p-6 '
             . 'transition-all duration-300 ease-out '
             . 'hover:-translate-y-1.5 hover:border-harbor hover:shadow-xl hover:shadow-ink/10 '
             . 'focus-visible:-translate-y-1.5 focus-visible:border-harbor focus-visible:outline-none '
             . 'motion-reduce:transition-none motion-reduce:hover:translate-y-0';
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</{{ $tag }}>
