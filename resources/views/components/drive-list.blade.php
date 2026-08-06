@props(['game', 'drives', 'newestFirst' => false])

{{--
    The drive chart — one row per drive, expandable to its plays. Fed from
    game_drives, which the page only queries while a tab showing this is
    active; this component never causes that read itself.

    Teams resolve against the game's own two sides rather than trusting the
    payload's embedded team objects — our models carry the light/dark logo
    pair and the route key.
--}}
@php
    $teams = collect([$game->homeTeam, $game->awayTeam])->filter()->keyBy('id');

    $rows = collect($drives);

    if ($newestFirst) {
        $rows = $rows->reverse()->values();
    }
@endphp

<div class="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-800">
    <ol class="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800/60">
        @foreach ($rows as $drive)
            @php
                $team = $teams->get((int) data_get($drive, 'team.id'));
                $isScore = (bool) data_get($drive, 'isScore');
                $plays = data_get($drive, 'plays', []);
            @endphp

            <li x-data="{ open: false }" wire:key="drive-{{ data_get($drive, 'id', $loop->index) }}">
                <button
                    type="button"
                    x-on:click="open = ! open"
                    class="flex w-full items-center gap-2.5 px-3 py-2 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40"
                    :aria-expanded="open"
                >
                    <x-team-logo :team="$team" size="xs" class="shrink-0" />

                    <div class="flex min-w-0 flex-1 flex-col">
                        <span class="flex items-center gap-1.5 text-stat">
                            <span @class([
                                'font-semibold',
                                'text-emerald-600 dark:text-emerald-400' => $isScore,
                            ])>{{ data_get($drive, 'displayResult') ?? data_get($drive, 'result') }}</span>

                            <span class="truncate text-zinc-500">{{ data_get($drive, 'description') }}</span>
                        </span>

                        @if (data_get($drive, 'start.text') || data_get($drive, 'end.text'))
                            <span class="truncate text-micro text-zinc-400">
                                {{ data_get($drive, 'start.text') }}@if (data_get($drive, 'end.text')) → {{ data_get($drive, 'end.text') }}@endif
                            </span>
                        @endif
                    </div>

                    @if ($plays !== [])
                        <flux:icon.chevron-down variant="micro" class="shrink-0 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-180'" />
                    @endif
                </button>

                @if ($plays !== [])
                    <ol x-show="open" x-collapse class="flex flex-col gap-1.5 border-t border-zinc-100 bg-zinc-50/60 px-3 py-2 dark:border-zinc-800/60 dark:bg-zinc-900/40">
                        @foreach ($plays as $play)
                            <li class="flex gap-2 text-micro" wire:key="play-{{ data_get($play, 'id', $loop->parent->index.'-'.$loop->index) }}">
                                <span class="w-10 shrink-0 text-zinc-400 tabular">
                                    {{ data_get($play, 'clock.displayValue') }}
                                </span>
                                <span @class([
                                    'min-w-0 flex-1',
                                    'font-medium text-emerald-700 dark:text-emerald-400' => (bool) data_get($play, 'scoringPlay'),
                                    'text-zinc-600 dark:text-zinc-300' => ! data_get($play, 'scoringPlay'),
                                ])>{{ data_get($play, 'text') }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </li>
        @endforeach
    </ol>
</div>
