{{-- Credit: Bootstrap Icons (https://icons.getbootstrap.com), MIT licensed. --}}

{{-- Shotgun's mark: dead-eye rings. Filled paths at a 16px viewBox, the
     pin-angle contract — `variant` controls SIZE only. --}}
@props([
    'variant' => 'outline',
])

@php
    $classes = Flux::classes('shrink-0')
        ->add(match ($variant) {
            'mini' => '[:where(&)]:size-5',
            'micro' => '[:where(&)]:size-4',
            default => '[:where(&)]:size-6',
        });
@endphp

<svg
    {{ $attributes->class($classes) }}
    data-flux-icon
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 16 16"
    fill="currentColor"
    aria-hidden="true"
    data-slot="icon"
>
    <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
    <path d="M8 13A5 5 0 1 1 8 3a5 5 0 0 1 0 10m0 1A6 6 0 1 0 8 2a6 6 0 0 0 0 12"/>
    <path d="M8 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6m0 1a4 4 0 1 0 0-8 4 4 0 0 0 0 8"/>
    <path d="M9.5 8a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0"/>
</svg>
