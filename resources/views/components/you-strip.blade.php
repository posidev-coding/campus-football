{{--
    THE YOU-STRIP — the viewer's own line before any table: identity on
    the left, stat columns on the right, wearing the viewer blue the
    standings rows already speak. Values arrive PRE-RENDERED from the
    caller with an em dash wherever there is no data — null means no
    data, and this component never substitutes one.

    Four columns fit 390px only because the identity cell truncates; a
    host that needs more drops a column rather than letting the document
    scroll (measured in the device harness, never by eye).

    Two variants (2026-09-01). `panel` is the blue tile the clubhouse's
    Standings tab has always worn. `bare` keeps the row and its four
    columns and drops the border, the fill and the horizontal padding, for
    a host that supplies the surface — the overview's week band, where
    the strip is the second row of a light card. The attribute bag lands
    on THIS element either way, so `data-you-strip` and a tour anchor
    stay on the strip itself.
--}}
@props([
    /** The viewer's display identity — handle when claimed, name until then. */
    'name',
    /** @var list<array{label: string, value: string}> */
    'stats' => [],
    /** `panel` (the blue tile) or `bare` (the row alone; the host paints the surface). */
    'variant' => 'panel',
    /**
     * The viewer's recent weeks from `App\Support\WeekTrends`, drawn as a
     * second row under the stats. Empty draws nothing, and the strip's markup
     * is exactly the one-row strip it always was.
     *
     * @var list<array{saturday: string, place: int, field: int, tied: bool, top_half: bool}>
     */
    'trend' => [],
])

{{--
    A SECOND ROW, not a fifth column: four columns are all 390px holds, and
    five pills beside them would squeeze the identity cell to nothing. The
    row wrapper only exists when there is a trend, so a host that passes none
    gets the one-row strip unchanged.
--}}
<div {{ $attributes->class([
    'flex items-center gap-4 py-3' => $trend === [],
    'flex flex-col gap-2 py-3' => $trend !== [],
    'rounded-xl border border-blue-200/70 bg-blue-50/60 px-4 dark:border-blue-900/40 dark:bg-blue-950/30' => $variant === 'panel',
]) }}>
    @if ($trend !== [])
        <div class="flex items-center gap-4">
    @endif
    <div class="min-w-0 flex-1">
        <p class="text-micro font-medium uppercase tracking-wide text-blue-700/80 dark:text-blue-300/80">You</p>
        <p class="truncate font-semibold leading-tight">{{ $name }}</p>
    </div>

    @foreach ($stats as $stat)
        <div class="shrink-0 text-right">
            <p class="whitespace-nowrap text-micro text-zinc-500 dark:text-zinc-400">{{ $stat['label'] }}</p>
            <p class="tabular whitespace-nowrap text-sm font-bold">{{ $stat['value'] }}</p>
        </div>
    @endforeach
    @if ($trend !== [])
        </div>

        <x-week-trend :weeks="$trend" />
    @endif
</div>
