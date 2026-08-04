@props([
    'weeks' => [],
    'selected' => null,
    'model' => 'week',
])

{{--
    Horizontally scrolling week selector, the way a sports app carries it: the
    whole season visible by swiping rather than hidden behind a dropdown.

    Each pill shows the week over its INCLUSIVE date range. ESPN publishes weeks
    that abut — week 1 ends the same day week 2 starts — so CfbCalendar pulls the
    end back a day before it gets here; otherwise two consecutive pills both
    claim the same date and it reads like a bug.

    Keyed on week id, not week number: the postseason's "Bowls" is also week 1,
    so a number-keyed selector collides them and makes the bowl slate
    unreachable.
--}}
@if ($weeks !== [])
    <div
        x-data="{
            center() {
                const active = this.$refs.strip?.querySelector('[data-active=true]')
                active?.scrollIntoView({ block: 'nearest', inline: 'center' })
            },
        }"
        x-init="$nextTick(() => center())"
        {{ $attributes->class(['-mx-4 border-b border-zinc-200 dark:border-zinc-800']) }}
    >
        {{-- Scrolls within itself so the page body never scrolls sideways. --}}
        <div
            x-ref="strip"
            class="flex snap-x snap-mandatory gap-1 overflow-x-auto scroll-smooth px-4 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
            @foreach ($weeks as $week)
                @php $active = (int) $selected === (int) $week['week_id']; @endphp

                <button
                    type="button"
                    wire:click="$set('{{ $model }}', {{ $week['week_id'] }})"
                    wire:key="week-{{ $week['week_id'] }}"
                    data-active="{{ $active ? 'true' : 'false' }}"
                    @class([
                        'flex shrink-0 snap-start flex-col items-center gap-0.5 rounded-lg px-3 py-2 text-center transition-colors',
                        'bg-zinc-100 dark:bg-zinc-800' => $active,
                        'hover:bg-zinc-50 dark:hover:bg-zinc-900' => ! $active,
                    ])
                >
                    <span @class([
                        'text-stat font-bold tracking-tight whitespace-nowrap',
                        'text-zinc-900 dark:text-zinc-100' => $active,
                        'text-zinc-500 dark:text-zinc-400' => ! $active,
                    ])>{{ $week['label'] }}</span>

                    <span class="text-micro whitespace-nowrap text-zinc-400 dark:text-zinc-500">
                        {{ $week['range'] }}
                    </span>
                </button>
            @endforeach
        </div>
    </div>
@endif
