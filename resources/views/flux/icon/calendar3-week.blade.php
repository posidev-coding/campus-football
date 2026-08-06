{{-- Credit: Bootstrap Icons (https://icons.getbootstrap.com), MIT licensed. --}}

{{--
    The Around the League trigger on the game scorebug — a week-of-boxes
    calendar, the closest glyph to "the rest of the slate". Bootstrap set,
    hand-added under the same contract as pin-angle: filled 16px paths,
    `variant` controls SIZE only.
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
    <path d="M14 0H2a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2M1 3.857C1 3.384 1.448 3 2 3h12c.552 0 1 .384 1 .857v10.286c0 .473-.448.857-1 .857H2c-.552 0-1-.384-1-.857z"/>
    <path d="M12 7a1 1 0 1 1 0-2 1 1 0 0 1 0 2m-4 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2m-5 3a1 1 0 1 1 2 0 1 1 0 0 1-2 0m5 1a1 1 0 1 1 0-2 1 1 0 0 1 0 2"/>
</svg>
