{{--
    THE WEEK RIBBON — the lobby's dateline, wearing the group-hero band
    grammar so the Picks area opens the way a clubhouse does. Facts only:
    the week's identity from the calendar, and ONE clock line — whichever
    of these the moment earns, in order: games live now, the first
    kickoff ahead, a commissioner's slate deadline.

    `entry` is a CfbCalendar::defaultWeekEntry() array; the caller skips
    this component entirely when the calendar has nothing — null means no
    ribbon, never a substituted week.
--}}
@props([
    /** @var array<string, mixed> */
    'entry',
    /** @var array{type: string, at: \Carbon\CarbonInterface|null}|null */
    'clock' => null,
])

<div {{ $attributes->class(['flex items-center justify-between gap-3 rounded-xl bg-zinc-900 px-4 py-3 text-white dark:border dark:border-zinc-800 dark:bg-zinc-900']) }}>
    <div class="flex min-w-0 items-baseline gap-2">
        <p class="shrink-0 text-lg font-bold leading-tight">{{ Str::title(Str::lower($entry['label'])) }}</p>

        @if ($entry['range'] ?? null)
            <p class="min-w-0 truncate text-sm text-zinc-400">{{ $entry['range'] }}</p>
        @endif
    </div>

    @if ($clock !== null)
        <p class="flex shrink-0 items-center gap-1.5 text-sm font-medium">
            @if ($clock['type'] === 'live')
                <span class="flex items-center gap-1.5 font-semibold text-red-400">
                    <x-live-dot />
                    Games live
                </span>
            @elseif ($clock['type'] === 'kick' && $clock['at'] !== null)
                <span class="text-zinc-300">First kick {{ $clock['at']->setTimezone(config('cfb.timezone'))->format('D g:ia') }}</span>
            @elseif ($clock['type'] === 'deadline' && $clock['at'] !== null)
                {{-- Through ET explicitly, like the kick branch beside it — Cadence
                     instants already carry the zone, so this is a no-op that
                     keeps the two branches from drifting apart again. --}}
                <span class="text-zinc-300">Slates due {{ $clock['at']->setTimezone(config('cfb.timezone'))->format('D g:ia') }}</span>
            @endif
        </p>
    @endif
</div>
