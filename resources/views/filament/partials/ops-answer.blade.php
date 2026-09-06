{{--
    One named question's answer, above the box that asked it.

    Nothing here was written by a model. The model named a key and a window;
    every number below came out of AnalyticsCatalog, and every null renders as
    the words "no data" rather than as a zero — the catalog withholds a rate
    below its floor, and a 0% is the most confident possible way to say "we
    cannot tell yet".

    `since` is part of the answer and not a footnote: a ninety-day count off a
    sensor that shipped a fortnight ago is a two-week number wearing a
    three-month label.

    Lives under resources/views/filament, one of the two trees the panel's
    Tailwind scans. Anywhere else and every class here compiles to nothing.
--}}
@php
    /** @var array<string, mixed>|null $answer */
    /** @var string|null $miss */
@endphp

@if ($answer === null)
    <div class="rounded-lg bg-gray-50 p-4 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">
        {{ $miss }}
        <span class="block pt-1 text-xs text-gray-500 dark:text-gray-400">
            The charts on Overview, Audience and Pick'em answer more than this box can.
        </span>
    </div>
@else
    <div class="flex flex-col gap-4">
        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $answer['asked'] }}</p>
            <p class="text-base font-semibold">{{ $answer['title'] }}</p>

            <p class="pt-0.5 text-xs text-gray-500 dark:text-gray-400">
                @if ($answer['range'])
                    Over {{ $answer['range'] }}.
                @endif

                {{-- Three states. A question that does not report a `since`
                     says nothing about one; a null `since` means the sensor
                     was not counting in this window at all, which is a
                     different thing from a window full of zeroes. --}}
                @if ($answer['dated'])
                    @if ($answer['since'])
                        Counted since {{ $answer['since'] }}.
                    @else
                        The sensor has not counted a day in this window yet.
                    @endif
                @endif
            </p>
        </div>

        {{-- A real state, and reachable: pick'em health on a week with no
             slate published answers with an empty list. An empty box would
             read as a broken modal, which is the one thing it is not. --}}
        @if ($answer['rows'] === [] && $answer['tables'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nothing to report — the question ran and found no rows in this window.
            </p>
        @endif

        @if ($answer['rows'] !== [])
            <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm sm:grid-cols-3">
                @foreach ($answer['rows'] as $row)
                    <div class="min-w-0">
                        <dt class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $row['label'] }}</dt>
                        <dd @class([
                            'font-semibold tabular-nums',
                            'font-normal text-gray-400 dark:text-gray-500' => $row['value'] === 'no data',
                        ])>{{ $row['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @foreach ($answer['tables'] as $table)
            <div class="flex flex-col gap-1">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $table['label'] }}</p>

                {{-- Its own horizontal scroll: a cohort grid is eight columns
                     wide and the modal is not. --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                @foreach ($table['columns'] as $column)
                                    <th class="pr-4 pb-1 font-medium whitespace-nowrap">{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($table['rows'] as $row)
                                <tr class="border-t border-gray-100 dark:border-white/10">
                                    @foreach ($row as $cell)
                                        <td @class([
                                            'py-1 pr-4 tabular-nums whitespace-nowrap',
                                            'text-gray-400 dark:text-gray-500' => $cell === 'no data',
                                        ])>{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($table['more'] > 0)
                    <p class="text-xs text-gray-400 dark:text-gray-500">and {{ $table['more'] }} more</p>
                @endif
            </div>
        @endforeach
    </div>
@endif
