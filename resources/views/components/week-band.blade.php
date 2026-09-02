{{--
    THE WEEK BAND — the overview's dateline and the reader's own line, one
    light card instead of two dark-and-blue tiles (2026-09-01, pass 2).

    It replaced `x-week-ribbon` (a zinc-900 band wearing the OLD group-hero
    grammar, which went light the same day) and the blue you-strip call
    beneath it: at 390 a seated reader met switcher 24 · plate 33 · ribbon
    49 · you-strip 59 before any content, in three container treatments.
    The band is the clubhouse hero's own surface — white, zinc-200 border,
    zinc-900 with a zinc-800 border in dark — so the two screens open the
    same way.

    Row 1 is the dateline plus ONE clock line, by urgency (games live now
    → the next kickoff → a commissioner's slate deadline), the three
    branches verbatim from the ribbon. Row 2 is `x-you-strip` in its `bare`
    variant. The two rows are SIBLINGS, each carrying its own tour anchor
    (`data-tour="week"`, `data-tour="balance"`): one root attribute bag
    cannot carry two, and the picks walk spotlights them separately. From
    `md` the rows sit side by side.

    `entry` is a CfbCalendar::defaultWeekEntry() array or null; `name` is
    the viewer's identity or null. A null entry renders no dateline, a null
    name renders no strip — never a substituted week, never an invented
    line — and the caller skips the band when both are null. The first run
    keeps `data-tour="week"` and no `data-you-strip`, which is what the
    tour's anchor loop and the fork guard pin.
--}}
@props([
    /** @var array<string, mixed>|null */
    'entry' => null,
    /** @var array{type: string, at: \Carbon\CarbonInterface|null}|null */
    'clock' => null,
    /** The viewer's display identity, or null for no strip. */
    'name' => null,
    /** @var list<array{label: string, value: string}> */
    'stats' => [],
])

<div {{ $attributes->class(['flex flex-col rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 md:flex-row md:items-stretch']) }}>
    @if ($entry !== null)
        <div class="flex items-center justify-between gap-3 px-4 py-3 md:shrink-0" data-tour="week">
            <div class="flex min-w-0 items-baseline gap-2">
                <p class="shrink-0 text-lg font-bold leading-tight">{{ Str::title(Str::lower($entry['label'])) }}</p>

                @if ($entry['range'] ?? null)
                    <p class="min-w-0 truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $entry['range'] }}</p>
                @endif
            </div>

            @if ($clock !== null)
                <p class="flex shrink-0 items-center gap-1.5 text-sm font-medium">
                    @if ($clock['type'] === 'live')
                        <span class="flex items-center gap-1.5 font-semibold text-red-600 dark:text-red-400">
                            <x-live-dot />
                            Games live
                        </span>
                    @elseif ($clock['type'] === 'kick' && $clock['at'] !== null)
                        <span class="text-zinc-600 dark:text-zinc-300">First kick {{ $clock['at']->setTimezone(config('cfb.timezone'))->format('D g:ia') }}</span>
                    @elseif ($clock['type'] === 'deadline' && $clock['at'] !== null)
                        {{-- Through ET explicitly, like the kick branch beside it — Cadence
                             instants already carry the zone, so this is a no-op that
                             keeps the two branches from drifting apart again. --}}
                        <span class="text-zinc-600 dark:text-zinc-300">Slates due {{ $clock['at']->setTimezone(config('cfb.timezone'))->format('D g:ia') }}</span>
                    @endif
                </p>
            @endif
        </div>
    @endif

    @if ($name !== null)
        <x-you-strip
            variant="bare"
            data-you-strip
            data-tour="balance"
            :name="$name"
            :stats="$stats"
            @class([
                'px-4',
                'border-t border-zinc-100 dark:border-zinc-800/60 md:border-t-0 md:border-l md:flex-1' => $entry !== null,
                'md:flex-1' => $entry === null,
            ])
        />
    @endif
</div>
