{{--
    The clubhouse hero — the team-hero grammar on a NEUTRAL band, because a
    group has no team color (yet): a deep zinc surface in both modes rather
    than a brand fill, so it reads as the same object beside the branded
    team pages without pretending to a palette it doesn't have.

    The `actions` slot is the hero's right edge: the invite-copy control,
    and later the commissioner's gear menu.

    The `icon` slot is the LEFT edge, and it defaults to the plain mark. A
    commissioner's screen passes the same mark wrapped in an upload control
    — the band itself has no business knowing who may change it.

    The mark is SMALLER at base and grows at sm, because this row was
    already truncating the group name at 390px before it had one: at 40px
    it costs the title five characters, at 36px it costs three. Every
    caller passes the same pair of sizes.
--}}
@props([
    'group',
    /** The group's contest this season, or null while it has none. */
    'contest' => null,
    'membersCount' => 0,
    /** Overrides the default mode · year · members subline when set. */
    'meta' => null,
])

<div {{ $attributes->class(['flex items-center gap-3 rounded-xl bg-zinc-900 px-4 py-4 text-white dark:border dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100']) }}>
    @if ($icon ?? false)
        {{ $icon }}
    @else
        <x-group-icon :group="$group" class="size-9 text-micro sm:size-11 sm:text-sm" />
    @endif

    <div class="min-w-0 flex-1">
        <div class="flex min-w-0 items-center gap-2">
            <h1 class="min-w-0 truncate text-xl font-bold leading-tight sm:text-2xl">{{ $group->name }}</h1>

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
            <span class="hidden shrink-0 rounded bg-white/15 px-1.5 py-0.5 text-micro font-semibold sm:inline-block">
                {{ $group->isLobby() ? 'Public' : 'Private' }}
            </span>
        </div>

        <p class="truncate pt-0.5 text-sm text-zinc-300">
            <span class="font-semibold sm:hidden">{{ $group->isLobby() ? 'Public' : 'Private' }} &middot;</span>

            {{ $meta ?? collect([
                $contest?->mode->label(),
                $contest?->season_year,
                $membersCount.' '.Str::plural('member', $membersCount),
            ])->filter()->implode(' · ') }}
        </p>
    </div>

    @if ($actions ?? false)
        <div class="flex shrink-0 items-center gap-2">
            {{ $actions }}
        </div>
    @endif
</div>
