{{--
    The clubhouse hero — the team-hero grammar on a NEUTRAL band, because a
    group has no team color (yet). LIGHT since 2026-09-01: white with a
    zinc-200 border (zinc-900 with a zinc-800 border in dark), the same
    grammar as every card on the screen. It was a deep zinc surface in both
    modes, which read as one object beside the branded team pages but gave
    an uploaded mark nothing to sit against — a dark icon on a dark band
    vanished, and the initials tile was a wash on a wash. Every child that
    was painted for the dark band (the initials tile, the kind chip, the
    action button, the meta line) is repainted with it.

    The `actions` slot is the hero's right edge, and it holds ONE control:
    the commissioner's cog. The Talk door moved to its own gutter tab and
    the invite-copy button to the Invite stop, each for the same reason —
    a stop that owns the thing does not need a worse door beside the name.
    The wrapper renders only when the slot has CONTENT: a passed
    ComponentSlot is truthy even when empty, and an empty flex wrapper still
    spends its gap on the title row.

    The `icon` slot is the LEFT edge, and it defaults to the plain mark. A
    commissioner's screen passes the same mark wrapped in an upload control
    — the band itself has no business knowing who may change it.

    The mark is SMALLER at base and grows at sm, because this row was
    already truncating the group name at 390px before it had one: at 40px
    it costs the title five characters, at 36px it costs three. Every
    caller passes the same pair of sizes.

    The `title` slot replaces the h1: the clubhouse passes the group
    switcher here, worn as the name, with an sr-only h1 beside it so the
    heading survives for assistive tech. The caller that passes one owns
    the heading semantics; nothing else about the band changes.
--}}
@props([
    'group',
    /** The group's contest this season, or null while it has none. */
    'contest' => null,
    'membersCount' => 0,
    /** Overrides the default mode · year · members subline when set. */
    'meta' => null,
])

<div {{ $attributes->class(['flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-4 text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100']) }}>
    @if ($icon ?? false)
        {{ $icon }}
    @else
        <x-group-icon :group="$group" class="size-9 text-micro sm:size-11 sm:text-sm" />
    @endif

    <div class="min-w-0 flex-1">
        <div class="flex min-w-0 items-center gap-2">
            @if ($title ?? false)
                {{ $title }}
            @else
                <h1 class="min-w-0 truncate text-xl font-bold leading-tight sm:text-2xl">{{ $group->name }}</h1>
            @endif

            {{-- The kind, always — one word. It used to render only for
                 lobbies, which made "Public" a mark some rooms wore and
                 said nothing at all about the container a private group
                 is. A badge only one side of a pair wears is a badge
                 nobody reads as a pair. --}}
            {{-- Above sm only, since the mark arrived: at 390px the title
                 row now carries a mark, a chip and three controls, and the
                 h1 was losing to two characters. The KIND is still said on
                 both sides at every width — it moves to the head of the
                 meta line below sm rather than going anywhere. --}}
            <span class="hidden shrink-0 rounded bg-zinc-100 px-1.5 py-0.5 text-micro font-semibold text-zinc-600 sm:inline-block dark:bg-white/15 dark:text-zinc-100">
                {{ $group->isLobby() ? 'Public' : 'Private' }}
            </span>
        </div>

        <p class="truncate pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
            <span class="font-semibold sm:hidden">{{ $group->isLobby() ? 'Public' : 'Private' }} &middot;</span>

            {{ $meta ?? collect([
                $contest?->mode->label(),
                $contest?->season_year,
                $membersCount.' '.Str::plural('member', $membersCount),
            ])->filter()->implode(' · ') }}
        </p>
    </div>

    {{-- Content, not truthiness: a passed slot is an object and an object
         is always true, so `?? false` rendered this wrapper for every plain
         member and spent 12px of the title row on nothing. And not
         isNotEmpty() alone either — Livewire's <!--[if BLOCK]--> markers
         ride inside a slot's string, so a slot whose every @if rendered
         nothing still reads as text. Strip them, then ask. --}}
    @php
        $hasActions = isset($actions)
            && trim(preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', (string) $actions)) !== '';
    @endphp

    @if ($hasActions)
        <div class="flex shrink-0 items-center gap-2">
            {{ $actions }}
        </div>
    @endif
</div>
