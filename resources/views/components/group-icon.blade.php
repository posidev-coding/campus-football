{{--
    THE CLUBHOUSE MARK — a group's uploaded icon, or its initials.

    Null is the normal state and stays a first-class render: most groups
    never upload anything, so the initials tile is the path this component
    is mostly on, not a placeholder for a missing file. Nothing here
    invents a stand-in image.

    A rounded SQUARE rather than the circle a person wears: a group is a
    container, and the mode tile on x-group-card already taught the shape.
    Size and type scale come from the caller through `class`, because the
    hero wears it at 44px and a list row would not.
--}}
@props([
    'group',
])

@php
    $iconUrl = $group->iconUrl();
@endphp

<span {{ $attributes->class(['flex shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white/15 font-bold uppercase leading-none']) }}>
    @if ($iconUrl !== null)
        <img src="{{ $iconUrl }}" alt="" class="size-full object-cover">
    @else
        {{ $group->initials() }}
    @endif
</span>
