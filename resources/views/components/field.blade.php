{{-- Input form seragam: label, kolom, tombol lihat/sembunyikan (untuk password), dan pesan error --}}
@props(['label', 'name', 'type' => 'text', 'value' => null])

@php $isPassword = $type === 'password'; @endphp

<div x-data="{ show: false }">
    <label for="{{ $name }}" class="block text-sm font-medium">{{ $label }}</label>
    <div class="relative mt-1.5">
        <input id="{{ $name }}" name="{{ $name }}"
               @if ($isPassword) :type="show ? 'text' : 'password'" @else type="{{ $type }}" @endif
               @unless ($isPassword) value="{{ old($name, $value) }}" @endunless
               @if ($errors->has($name)) aria-invalid="true" @endif
               {{ $attributes->class([
                    'w-full rounded-xl border bg-white px-4 py-2.5 text-sm transition-colors duration-200',
                    'focus:border-harbor focus:outline-none focus:ring-4 focus:ring-harbor/20',
                    'border-red-400' => $errors->has($name),
                    'border-slate-300' => ! $errors->has($name),
                    'pr-24' => $isPassword,
               ]) }}>
        @if ($isPassword)
            <button type="button" x-on:click="show = ! show" x-text="show ? 'Sembunyikan' : 'Lihat'"
                    class="absolute inset-y-0 right-3 text-xs font-semibold text-harbor transition-transform duration-150 btn-fx"></button>
        @endif
    </div>
    @error($name)
        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
