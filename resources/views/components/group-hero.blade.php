{{--
    The clubhouse hero — the team-hero grammar on a NEUTRAL band, because a
    group has no team color (yet): a deep zinc surface in both modes rather
    than a brand fill, so it reads as the same object beside the branded
    team pages without pretending to a palette it doesn't have.

    The `actions` slot is the hero's right edge: the invite-copy control,
    and later the commissioner's gear menu.
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
    <div class="min-w-0 flex-1">
        <div class="flex min-w-0 items-center gap-2">
            <h1 class="min-w-0 truncate text-xl font-bold leading-tight sm:text-2xl">{{ $group->name }}</h1>

            @if ($group->isLobby())
                <span class="shrink-0 rounded bg-white/15 px-1.5 py-0.5 text-micro font-semibold">Public</span>
            @endif
        </div>

        <p class="truncate pt-0.5 text-sm text-zinc-300">
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
