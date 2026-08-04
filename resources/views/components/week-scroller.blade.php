@props([
    'weeks' => [],
    'selected' => null,
    'bracket' => '',
    // Whether to break out of the parent's horizontal padding. False when the
    // scroller sits inside a container that already bleeds, which is the case
    // on the scoreboard's sticky block — two negative margins would double up.
    'bleed' => true,
])

{{--
    Horizontally scrolling week selector, the way a sports app carries it: the
    whole season visible by swiping rather than hidden behind a dropdown.

    Each pill shows the week over its INCLUSIVE date range. ESPN publishes weeks
    that abut — week 1 ends the same day week 2 starts — so CfbCalendar pulls the
    end back a day before it gets here; otherwise two consecutive pills both
    claim the same date and it reads like a bug.

    The postseason contributes TWO pills, BOWLS and CFP, which share one
    `week_id` — so selection is keyed on the pair, not the id alone. ESPN
    publishes the postseason as a single 46-game week, and leaving it that way
    buries the playoff inside the bowl slate.
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
        {{ $attributes->class([
            'border-b border-zinc-200 dark:border-zinc-800',
            '-mx-4' => $bleed,
        ]) }}
    >
        {{-- Scrolls within itself so the page body never scrolls sideways. --}}
        <div
            x-ref="strip"
            @class([
                'flex snap-x snap-mandatory gap-1 overflow-x-auto scroll-smooth pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
                'px-4' => $bleed,
            ])
        >
            @foreach ($weeks as $week)
                @php
                    $active = (int) $selected === (int) $week['week_id']
                        && (string) $bracket === (string) ($week['bracket'] ?? '');
                @endphp

                <button
                    type="button"
                    wire:click="selectWeek({{ $week['week_id'] }}, '{{ $week['bracket'] ?? '' }}')"
                    wire:key="week-{{ $week['week_id'] }}-{{ $week['bracket'] ?? 'all' }}"
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
