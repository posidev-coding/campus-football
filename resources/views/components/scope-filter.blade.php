@props([
    'year' => null,
    'selected' => 'top25',
    'model' => 'scope',
    'includeFcs' => false,
    'title' => null,
])

@php
    $year ??= now()->year;
    $options = App\Support\Scope::options($year, $includeFcs);
@endphp

{{--
    The section title with its scope selector sitting underneath, the way ESPN
    stacks "NCAA Football" over "Top 25 ⌄". Keeping them together means the
    filter reads as a qualifier on the heading rather than as a stray control.

    Options are Top 25 first (the default — opening on 800 teams' worth of games
    is not a useful first screen), then FBS, then the conferences by short_name.
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
                <flux:menu.item
                    wire:click="$set('{{ $model }}', '{{ $option['value'] }}')"
                    wire:key="scope-{{ $option['value'] }}"
                    @class(['font-semibold' => $selected === $option['value']])
                >{{ $option['label'] }}</flux:menu.item>
            @endforeach
        </flux:menu>
    </flux:dropdown>
</div>
