@props([
    /** list<array{value:string, label:string}> — or a plain value => label map. */
    'items' => [],
    'selected' => '',
    /** Livewire property to $set — or pass `action`, a method called with the value. */
    'model' => null,
    'action' => null,
    /** Accessible name for the group. Every caller passes one. */
    'label' => null,
    /** Per-screen wire:key prefix, so two gutters on sibling screens cannot collide. */
    'keyPrefix' => 'tab',
    /**
     * `shrink` drops into any flex row — centered over content, floated
     * beside other actions, or out on a plate. `block` fills its row and the
     * items share it equally, for 3-4-item categorical sub-scoping (stat
     * categories, position categories). `fill` fills the row too, but each
     * cell's base size is its own label and only the SPARE width is shared —
     * for a five-stop strip whose labels fit but whose equal fifths do not.
     */
    'variant' => 'shrink',
])

@php
    /*
     * The GUTTER TABS: a zinc track with the active item raised on a white
     * pad — the same language as the team page's section tabs, and the
     * replacement for the blue pill strips. Used where a plate cannot: more
     * tabs than two-or-three, or a second row of sub-nav under one.
     *
     * Nothing here scrolls: `block` divides the row, `shrink` sizes to
     * content, and a set that cannot fit at 390px either way belongs in an
     * x-filter-menu. Block runs px-2 where shrink runs px-3 — measured from
     * the font file, "Special Teams" at px-3 sits 0.03px from clipping a
     * three-up cell at 390.
     *
     * `fill` exists because `block`'s equal division cannot hold five. The
     * clubhouse strip at 390 has a 352px track inside; five EQUAL cells
     * give each 54.4px of label box, and "Standings" (64.2px) and "Members"
     * (59.7px) clip. Sized to content at px-2 the five labels total 298px,
     * so `flex-auto` (a cell's basis is its label; the spare 54px is shared)
     * never clips as long as the labels' sum fits — which five do and a
     * sixth ("Rules", 36.4px + padding) would only just, so a sixth stop is
     * an accordion or a menu, never a scroll. This is still not a scroll:
     * the track is `w-full` and overflow would be a design bug, not a
     * gesture.
     */
    if (! array_is_list($items)) {
        $items = collect($items)
            ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])
            ->values()
            ->all();
    }
@endphp

<div
    {{ $attributes->class([
        'flex h-8 rounded-lg bg-zinc-800/5 p-[3px] dark:bg-white/10',
        'w-full' => in_array($variant, ['block', 'fill'], true),
        'w-max' => $variant === 'shrink',
    ]) }}
    role="group"
    @if ($label) aria-label="{{ $label }}" @endif
>
    @foreach ($items as $item)
        @php
            $active = $selected === $item['value'];

            $click = $action
                ? "{$action}('{$item['value']}')"
                : "\$set('{$model}', '{$item['value']}')";
        @endphp

        <button
            type="button"
            wire:click="{{ $click }}"
            wire:key="{{ $keyPrefix }}-{{ $item['value'] ?: 'all' }}"
            @if ($active) aria-current="true" @endif
            @class([
                'flex items-center justify-center rounded-md text-sm font-medium whitespace-nowrap transition-colors',
                'min-w-0 flex-1 px-2' => $variant === 'block',
                'min-w-0 flex-auto px-2' => $variant === 'fill',
                'shrink-0 px-3' => $variant === 'shrink',
                'bg-white text-zinc-800 shadow-xs dark:bg-white/20 dark:text-white' => $active,
                'text-zinc-600 hover:text-zinc-800 dark:text-white/70 dark:hover:text-white' => ! $active,
            ])
        >{{ $item['label'] }}</button>
    @endforeach
</div>
