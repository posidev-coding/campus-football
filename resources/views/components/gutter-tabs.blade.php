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
     * categories, position categories).
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
        'w-full' => $variant === 'block',
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
                'shrink-0 px-3' => $variant === 'shrink',
                'bg-white text-zinc-800 shadow-xs dark:bg-white/20 dark:text-white' => $active,
                'text-zinc-600 hover:text-zinc-800 dark:text-white/70 dark:hover:text-white' => ! $active,
            ])
        >{{ $item['label'] }}</button>
    @endforeach
</div>
