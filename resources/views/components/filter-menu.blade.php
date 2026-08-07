@props([
    /** list<array{value:string, label:string, menuLabel?:string, disabled?:bool, note?:string, group?:?string}> */
    'items' => [],
    'selected' => '',
    /** Livewire property to $set — or pass `action`, a method called with the value. */
    'model' => null,
    'action' => null,
    /** Accessible name for the trigger; the visible text is only the current value. */
    'label' => null,
    'keyPrefix' => 'opt',
    'align' => 'start',
    /**
     * `default` is the zinc text button that every screen's chrome uses.
     * `accent` is for a control sitting ON a team's accent surface, where a
     * fixed zinc would be unreadable against 136 different colors: it draws
     * entirely in `currentColor`, which is the hero's computed text color and
     * the one pairing TeamPalette already proved readable there. Same ring as
     * the follow button's Following state, so the two read as a matched pair —
     * one filled action, one outlined qualifier.
     */
    'variant' => 'default',
])

@php
    /*
     * The text-button dropdown — this app's one idiom for "narrow the list",
     * as ESPN stacks "Top 25 ⌄" under its section title. A text button rather
     * than a boxed select so it reads as a qualifier on the content, not as a
     * form field. There are no select boxes in screen chrome at all — season,
     * class and poll ride this same idiom through x-season-menu.
     *
     * Items may carry `group` (rendered as menu group headings when it
     * changes), `disabled` (rendered as a plain div — menu items are focusable
     * and selectable, so a disabled one still lands under the keyboard), and
     * `note` (small text beside a disabled label saying why).
     */
    $current = collect($items)->firstWhere('value', $selected) ?? collect($items)->first();

    // Consecutive items sharing a group render under one heading; null groups
    // render bare. Sequence preserved — the caller owns the order.
    $chunks = [];

    foreach ($items as $item) {
        $group = $item['group'] ?? null;

        if ($chunks === [] || $chunks[array_key_last($chunks)]['group'] !== $group) {
            $chunks[] = ['group' => $group, 'items' => []];
        }

        $chunks[array_key_last($chunks)]['items'][] = $item;
    }
@endphp

<div {{ $attributes->class(['flex flex-col gap-0.5']) }}>
    <flux:dropdown position="bottom" :align="$align">
        <button
            type="button"
            @if ($label) aria-label="{{ $label }}" @endif
            @class([
                'group flex w-fit items-center gap-1 text-sm font-medium transition-colors',
                'py-0.5 text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => $variant === 'default',
                'h-7 shrink-0 rounded-md px-2.5 ring-1 ring-current/50 hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current' => $variant === 'accent',
            ])
        >
            {{ $current['label'] ?? '' }}
            <flux:icon
                name="chevron-down"
                variant="micro"
                @class([
                    'transition-colors',
                    'text-zinc-400 group-hover:text-current' => $variant === 'default',
                    'opacity-70' => $variant === 'accent',
                ])
            />
        </button>

        <flux:menu>
            @foreach ($chunks as $chunk)
                @if ($chunk['group'] !== null)
                    <flux:menu.group :heading="$chunk['group']">
                        @foreach ($chunk['items'] as $item)
                            <x-filter-menu.item :$item :$selected :$model :$action :$keyPrefix />
                        @endforeach
                    </flux:menu.group>
                @else
                    @foreach ($chunk['items'] as $item)
                        <x-filter-menu.item :$item :$selected :$model :$action :$keyPrefix />
                    @endforeach
                @endif
            @endforeach
        </flux:menu>
    </flux:dropdown>
</div>
