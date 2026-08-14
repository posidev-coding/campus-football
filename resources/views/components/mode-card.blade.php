{{--
    One contest mode as a choosable card — the creation wizard's "The Game"
    step and the pivot modal both explain modes through this, so the pitch
    can never drift between the two doors.

    Radio semantics: the caller puts these in a group and wires the tap;
    `selected` draws the chosen state. A mode whose available() is false
    renders dashed and disabled — visible but never tappable, the same
    gate the engine enforces. (All three are live today; the gate stays
    for whatever mode arrives next.)

    The rules line is an explainer a first-timer acts on, so it stays
    plain product vocabulary — the voice lives AROUND the cards. Each mode
    wears its own mark and colors from ContestMode's identity seam.
--}}
@props([
    /** @var App\Enums\ContestMode */
    'mode',
    'selected' => false,
])

@php
    $available = $mode->available();
    $blurb = $mode->blurb();
    $palette = $mode->palette();
@endphp

<button
    type="button"
    role="radio"
    aria-checked="{{ $selected ? 'true' : 'false' }}"
    @disabled(! $available)
    {{ $attributes->class([
        'flex w-full items-start gap-3 rounded-xl border p-4 text-start transition-colors',
        'border-blue-600 bg-blue-50/50 dark:border-blue-400 dark:bg-blue-950/30' => $selected,
        'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500' => $available && ! $selected,
        'cursor-not-allowed border-dashed border-zinc-300 dark:border-zinc-700' => ! $available,
    ]) }}
>
    <span @class([
        'flex size-9 shrink-0 items-center justify-center rounded-lg border',
        $palette['tile'],
        'opacity-50' => ! $available,
    ])>
        <flux:icon :name="$mode->icon()" variant="mini" class="{{ $palette['icon'] }}" />
    </span>

    <span class="min-w-0 flex-1">
        <span @class([
            'block text-base font-bold',
            'text-zinc-400 dark:text-zinc-500' => ! $available,
        ])>{{ $mode->label() }}</span>

        <span @class([
            'block pt-0.5 text-sm',
            'text-zinc-500 dark:text-zinc-400' => $available,
            'text-zinc-400 dark:text-zinc-500' => ! $available,
        ])>{{ $blurb }}</span>
    </span>

    @if ($selected)
        <flux:icon.check-circle-fill variant="mini" class="shrink-0 text-blue-600 dark:text-blue-400" />
    @endif
</button>
