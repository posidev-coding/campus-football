@props([
    'href',
    'icon',
    'label',
])

@php
    $active = $href !== '#' && request()->url() === $href;
@endphp

<a
    href="{{ $href }}"
    @if ($href !== '#') wire:navigate @endif
    @if ($active) aria-current="page" @endif
    {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-1 text-micro font-medium transition-colors '.($active ? 'text-zinc-900 dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-500')]) }}
>
    <flux:icon :name="$icon" variant="outline" class="size-6" />
    {{ $label }}
</a>
