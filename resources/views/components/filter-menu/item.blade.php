@props([
    /** array{value:string, label:string, menuLabel?:string, href?:string, disabled?:bool, note?:string} */
    'item' => [],
    'selected' => '',
    'model' => null,
    'action' => null,
    'keyPrefix' => 'opt',
])

@if ($item['disabled'] ?? false)
    {{-- Not a menu.item: those are focusable and selectable, so a disabled
         one still lands under the keyboard. --}}
    <div
        class="flex cursor-not-allowed items-center justify-between gap-3 px-2 py-1.5 text-sm text-zinc-400 dark:text-zinc-600"
        aria-disabled="true"
        wire:key="{{ $keyPrefix }}-{{ $item['value'] ?: 'all' }}"
    >
        {{ $item['label'] }}
        @if ($item['note'] ?? false)
            <span class="text-micro">{{ $item['note'] }}</span>
        @endif
    </div>
@elseif ($item['href'] ?? null)
    {{-- A NAVIGATING item — the group switcher's rows. The same menu
         species going somewhere instead of setting something, so a
         dropdown that navigates is still an x-filter-menu and never a
         second dropdown idiom. `model` and `action` are ignored for it;
         `note` rides the row as Flux's own suffix. --}}
    <flux:menu.item
        :href="$item['href']"
        wire:navigate
        wire:key="{{ $keyPrefix }}-{{ $item['value'] ?: 'all' }}"
        :suffix="$item['note'] ?? null"
        @class(['font-semibold' => $selected === $item['value']])
    >
        {{ $item['menuLabel'] ?? $item['label'] }}
    </flux:menu.item>
@else
    @php
        // Built here rather than with @if in the tag: Blade directives cannot sit
        // inside a component tag's attribute list — that is a parse error.
        $click = $action
            ? "{$action}('{$item['value']}')"
            : "\$set('{$model}', '{$item['value']}')";
    @endphp

    <flux:menu.item
        wire:click="{{ $click }}"
        wire:key="{{ $keyPrefix }}-{{ $item['value'] ?: 'all' }}"
        :suffix="$item['note'] ?? null"
        @class(['font-semibold' => $selected === $item['value']])
    >
        {{-- `menuLabel` lets the menu say more than the trigger: the position
             filter's trigger reads "QB" while its menu row reads
             "QB · Quarterbacks". --}}
        {{ $item['menuLabel'] ?? $item['label'] }}
    </flux:menu.item>
@endif
