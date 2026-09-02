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
])

<div {{ $attributes->class([
    'flex items-center gap-4 py-3',
    'rounded-xl border border-blue-200/70 bg-blue-50/60 px-4 dark:border-blue-900/40 dark:bg-blue-950/30' => $variant === 'panel',
]) }}>
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
</div>
