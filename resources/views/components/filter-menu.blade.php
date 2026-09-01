@props([
    /** list<array{value:string, label:string, menuLabel?:string, href?:string, disabled?:bool, note?:string, group?:?string}> */
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
     *
     * `hero` is the clubhouse TITLE: the group switcher worn as the name on
     * the group hero's band. It inherits `currentColor` like `accent`, but
     * carries no ring — a ring around a title reads as a button, not a name —
     * so the chevron is the whole affordance. The label wraps to two lines
     * rather than truncating, because at 390px the band already spends its
     * width on a mark and two controls and a clipped name is a name nobody
     * can read.
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
     * and selectable, so a disabled one still lands under the keyboard),
     * `note` (small text beside the label — why a row is disabled, or a count
     * on a live one), and `href` (the row NAVIGATES instead of setting the
     * property — the group switcher's rows; the same idiom, going somewhere).
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

<div {{ $attributes->class(['flex flex-col gap-0.5', 'min-w-0' => $variant === 'hero']) }}>
    <flux:dropdown position="bottom" :align="$align">
        <button
            type="button"
            @if ($label) aria-label="{{ $label }}" @endif
            @class([
                'group flex items-center transition-colors',
                'w-fit gap-1 text-sm font-medium' => $variant !== 'hero',
                '-mx-1 -my-1.5 px-1 py-2 text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => $variant === 'default',
                '-my-1 h-9 shrink-0 rounded-md px-2.5 ring-1 ring-current/50 hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current' => $variant === 'accent',
                'min-w-0 max-w-full gap-1.5 rounded-md text-start text-xl font-bold leading-tight hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current sm:text-2xl' => $variant === 'hero',
            ])
        >
            {{-- The hero label CLAMPS rather than truncates: two lines of a
                 long name beat one line of a clipped one on a band that
                 has no width to spare. --}}
            <span @class(['min-w-0', 'line-clamp-2 break-words' => $variant === 'hero'])>{{ $current['label'] ?? '' }}</span>
            <flux:icon
                name="chevron-down"
                :variant="$variant === 'hero' ? 'mini' : 'micro'"
                @class([
                    'shrink-0 transition-colors',
                    'text-zinc-400 group-hover:text-current' => $variant === 'default',
                    'opacity-70' => $variant !== 'default',
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
