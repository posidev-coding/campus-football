@props([
    /** array<string, string> value => label. Two tabs, three at most. */
    'tabs' => [],
    'selected' => '',
    'model' => 'view',
    /** Per-screen wire:key prefix, so two plates on sibling screens cannot collide. */
    'keyPrefix' => 'view',
    /**
     * Full-bleed variant (mobile only), for screens whose hero separates this
     * from x-section-nav's identical underlines. A screen where the section
     * strip sits 20px above must NOT bleed — two full-bleed underlined rules
     * that close together read as one confusing double strip.
     */
    'bleed' => false,
])

@php
    /*
     * The PLATE: one ruled row that switches between a screen's content areas
     * — "which list am I looking at" — and serves as the shelf its actions
     * sit on, right-aligned in the `actions` slot (typically text-button
     * dropdown filters; never a select box). The tabs rest their active
     * underline directly ON the rule, which is what makes the row read as one
     * object rather than tabs floating above a divider.
     *
     * Two content areas, three at the very most. A plate is a fork in the
     * screen, not a menu of categories — anything wider belongs in
     * x-gutter-tabs (more tabs than fit here, or a second row of sub-nav like
     * the Stats screen's category gutter).
     */
    if (count($tabs) > 3) {
        throw new InvalidArgumentException('x-plate holds two or three tabs; more belong in x-gutter-tabs.');
    }
@endphp

<div @class([
    'flex items-center justify-between gap-3 border-b border-zinc-200 dark:border-zinc-800',
    '-mx-4 px-4 sm:mx-0 sm:px-0' => $bleed,
])>
    {{-- -ml-1/pl-1 keeps the first button's focus ring visible. --}}
    <div @class(['flex min-w-0', '-ml-1 pl-1' => ! $bleed, 'flex-1 sm:flex-none' => $bleed])>
        @foreach ($tabs as $value => $label)
            @php $active = $selected === $value; @endphp

            {{-- `pt-0.5 pb-2` mirrors the actions column exactly: its menu
                 triggers wear `py-0.5` inside a `pb-2` shelf, so the tab takes
                 the same 2px above the text and 8px below to keep both columns
                 32px tall with the text at the same offset. `items-center`
                 alone cannot arrange that — it centers the equal BOXES, not
                 the text inside them, and the tab text sat 2px high. Taken
                 out of the bottom padding rather than added on, so the active
                 underline still rests directly on the rule. --}}
            <button
                type="button"
                wire:click="$set('{{ $model }}', '{{ $value }}')"
                wire:key="{{ $keyPrefix }}-{{ $value }}"
                @if ($active) aria-current="page" @endif
                @class([
                    'border-b-2 pt-0.5 pb-2 text-sm font-medium transition-colors',
                    'shrink-0 whitespace-nowrap px-3 first:pl-0 sm:px-4 sm:first:pl-4' => ! $bleed,
                    'flex-1 px-2 sm:flex-none sm:px-4' => $bleed,
                    'border-zinc-900 text-zinc-900 dark:border-zinc-100 dark:text-zinc-100' => $active,
                    'border-transparent text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => ! $active,
                ])
            >{{ $label }}</button>
        @endforeach
    </div>

    @if ($actions ?? false)
        <div class="flex shrink-0 items-center gap-3 pb-2">
            {{ $actions }}
        </div>
    @endif
</div>
