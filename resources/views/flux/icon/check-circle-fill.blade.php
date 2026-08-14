{{-- Credit: Bootstrap Icons (https://icons.getbootstrap.com), MIT licensed. --}}

{{--
    The graded-WIN mark on a pick card, and the picked-side confirmation
    check. Filled rather than outline because it has to read at micro size
    on a team-color surface — see pin-angle for why Bootstrap's filled
    16px paths join the set by hand.
--}}
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
    <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
</svg>
