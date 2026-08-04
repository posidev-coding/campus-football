@props([
    'year' => null,
    'selected' => 'top25',
    'model' => 'scope',
    'includeFcs' => false,
    'top25' => true,
    'title' => null,
])

@php
    $year ??= now()->year;
    $options = App\Support\Scope::options($year, $includeFcs, $top25);
@endphp

{{--
    The scope selector, as ESPN stacks "Top 25 ⌄" under its section title.

    An option can be DISABLED — Top 25 is, until the season has a poll, which is
    the normal state all summer. Greying it out rather than hiding it says the
    filter exists and is not available yet; hiding it would look like the app
    lost a feature, and leaving it selectable meant the control read "Top 25"
    while quietly showing all 138 FBS teams.
--}}
<div {{ $attributes->class(['flex flex-col gap-0.5']) }}>
    @if ($title)
        <flux:heading size="xl">{{ $title }}</flux:heading>
    @endif

    <flux:dropdown position="bottom" align="start">
        <button
            type="button"
            class="group flex w-fit items-center gap-1 py-0.5 text-sm font-medium text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
        >
            {{ App\Support\Scope::label($selected, $year) }}
            <flux:icon name="chevron-down" variant="micro" class="text-zinc-400 transition-colors group-hover:text-current" />
        </button>

        <flux:menu>
            @foreach ($options as $option)
                @if ($option['disabled'])
                    {{-- Not a menu.item: those are focusable and selectable, so
                         a disabled one still lands under the keyboard. --}}
                    <div
                        class="flex cursor-not-allowed items-center justify-between gap-3 px-2 py-1.5 text-sm text-zinc-400 dark:text-zinc-600"
                        aria-disabled="true"
                        wire:key="scope-{{ $option['value'] }}"
                    >
                        {{ $option['label'] }}
                        <span class="text-micro">No poll yet</span>
                    </div>
                @else
                    <flux:menu.item
                        wire:click="$set('{{ $model }}', '{{ $option['value'] }}')"
                        wire:key="scope-{{ $option['value'] }}"
                        @class(['font-semibold' => $selected === $option['value']])
                    >{{ $option['label'] }}</flux:menu.item>
                @endif
            @endforeach
        </flux:menu>
    </flux:dropdown>
</div>
