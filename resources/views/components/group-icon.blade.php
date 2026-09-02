{{--
    THE CLUBHOUSE MARK — a group's uploaded icon, a conference room's
    conference shield, or the group's initials.

    Null is the normal state and stays a first-class render: most groups
    never upload anything, so the initials tile is the path this component
    is mostly on, not a placeholder for a missing file. Nothing here
    invents a stand-in image.

    A CONFERENCE ROOM'S identity is its conference — the SEC shield on the
    SEC Showdown — read off the logo ESPN synced onto `conferences.logo`
    (Group::conferenceLogoUrl). A room has no commissioner to upload
    anything, so in practice the shield is a room's only mark; an upload
    still wins where one exists, and a conference ESPN shipped no logo for
    falls through to initials rather than to a guess. ESPN ships no dark
    variant for a conference mark and a navy B1G on zinc-900 is a hole, so
    the shield rides a WHITE puck in both modes — the one puck that does
    not follow the page into dark (docs/ui-system.md, "A team logo never
    sits on the team's color").

    A rounded SQUARE rather than the circle a person wears: a group is a
    container, and the mode tile on x-group-card already taught the shape.
    `shape` lets a 32px row wear the mode tile's rounded-lg beside it; size
    and type scale come from the caller through `class`, because the hero
    wears it at 44px and a list row would not.

    The initials tile is a zinc-100 pad with zinc-700 letters on the light
    band (a white wash in dark), so it reads on the hero's white as well as
    on any card; an uploaded image is unchanged — it brings its own color.
--}}
@props([
    'group',
    /** The corner radius — rounded-xl on the hero, rounded-lg beside a mode tile, rounded-md in a menu row. */
    'shape' => 'rounded-xl',
])

@php
    $iconUrl = $group->iconUrl();
    $conferenceLogo = $iconUrl === null ? $group->conferenceLogoUrl() : null;
@endphp

<span {{ $attributes->class([
    'flex shrink-0 items-center justify-center overflow-hidden font-bold uppercase leading-none',
    $shape,
    'bg-zinc-100 text-zinc-700 dark:bg-white/15 dark:text-zinc-100' => $conferenceLogo === null,
    'bg-white p-1 ring-1 ring-inset ring-black/10' => $conferenceLogo !== null,
]) }}>
    @if ($iconUrl !== null)
        <img src="{{ $iconUrl }}" alt="" class="size-full object-cover">
    @elseif ($conferenceLogo !== null)
        <img src="{{ $conferenceLogo }}" alt="" loading="lazy" decoding="async" class="size-full object-contain">
    @else
        {{ $group->initials() }}
    @endif
</span>
