{{--
    Fourteen days of one person's presence, read out of `user_days`.

    TWO KINDS OF EMPTY CELL, drawn differently on purpose. A day the sensor was
    counting and this person did nothing is dim and says so. A day BEFORE the
    sensor was counting is dashed and says THAT — because a quiet cell there
    would be the app claiming somebody ignored it during a stretch nothing was
    watching, which is the same invention as writing a default for missing data.

    Nothing here is route-level and nothing joins `activity_events` to make it
    so: areas and features are the two bitmasks, labeled off their enums.

    Lives under resources/views/filament, one of the two trees the panel's
    Tailwind scans. Anywhere else and every class below compiles to nothing.
--}}
@php
    $strip = $getState();
@endphp

<div>
    <div class="grid grid-cols-7 gap-1.5 sm:grid-cols-[repeat(14,minmax(0,1fr))]">
        @foreach ($strip['days'] as $day)
            @php
                $present = $day['views'] !== null;

                $title = match (true) {
                    ! $day['counted'] => $day['date'].' — before the app started counting',
                    ! $present => $day['date'].' — no activity recorded',
                    default => collect([
                        $day['date'],
                        $day['views'].' '.str('view')->plural($day['views']),
                        $day['areas'] === [] ? null : implode(', ', $day['areas']),
                        $day['features'] === [] ? null : implode(', ', $day['features']),
                    ])->filter()->implode(' — '),
                };
            @endphp

            <div
                title="{{ $title }}"
                @class([
                    'flex flex-col items-center justify-center gap-0.5 rounded-lg py-2 text-center',
                    'border border-dashed border-gray-200 text-gray-300 dark:border-white/10 dark:text-gray-600' => ! $day['counted'],
                    'bg-gray-50 text-gray-400 dark:bg-white/5 dark:text-gray-500' => $day['counted'] && ! $present,
                    'bg-amber-100 text-amber-900 dark:bg-amber-400/20 dark:text-amber-200' => $present,
                ])
            >
                <span class="text-[0.625rem] font-medium uppercase tracking-wide">{{ $day['weekday'] }}</span>
                <span class="text-sm font-semibold tabular-nums">
                    {{-- No 0 for an absent day: there is no row, and inventing one is the whole rule. --}}
                    {{ $present ? $day['views'] : '·' }}
                </span>
            </div>
        @endforeach
    </div>

    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
        @if ($strip['since'] === null)
            The sensor has not counted a day yet — every cell above is a fortnight nothing was watching.
        @elseif ($strip['counted'] < count($strip['days']))
            Counting since {{ $strip['since'] }}. The dashed days are before that, not quiet.
        @else
            A dot is a day with no activity recorded. The number is screens read.
        @endif
    </p>
</div>
