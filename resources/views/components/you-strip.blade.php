{{--
    THE YOU-STRIP — the viewer's own line before any table: identity on
    the left, stat columns on the right, wearing the viewer blue the
    standings rows already speak. Values arrive PRE-RENDERED from the
    caller with an em dash wherever there is no data — null means no
    data, and this component never substitutes one.

    Four columns fit 390px only because the identity cell truncates; a
    host that needs more drops a column rather than letting the document
    scroll (measured in the device harness, never by eye).
--}}
@props([
    /** The viewer's display identity — handle when claimed, name until then. */
    'name',
    /** @var list<array{label: string, value: string}> */
    'stats' => [],
])

<div {{ $attributes->class(['flex items-center gap-4 rounded-xl border border-blue-200/70 bg-blue-50/60 px-4 py-3 dark:border-blue-900/40 dark:bg-blue-950/30']) }}>
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
