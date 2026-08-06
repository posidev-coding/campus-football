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
            class="group flex w-fit items-center gap-1 py-0.5 text-sm font-medium text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
        >
            {{ $current['label'] ?? '' }}
            <flux:icon name="chevron-down" variant="micro" class="text-zinc-400 transition-colors group-hover:text-current" />
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
