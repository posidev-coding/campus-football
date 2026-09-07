{{-- Credit: Bootstrap Icons (https://icons.getbootstrap.com), MIT licensed. --}}

{{-- The graded-PUSH mark on a pick card — the third of the set, and the
     reason it exists: a push wore x-circle-fill and told a reader they were
     wrong about a game where the number came out even. --}}
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
    <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M4.5 7.5a.5.5 0 0 0 0 1h7a.5.5 0 0 0 0-1z"/>
</svg>
