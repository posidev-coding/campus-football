@props([
    /** @var list<array{saturday: string, place: int, field: int, tied: bool, top_half: bool}> */
    'weeks' => [],
])

{{--
    A member's last few weeks as place pills, OLDEST first so the row reads
    left to right toward now, from `App\Support\WeekTrends`. Green is the top
    half of the room that week, red the bottom half, and the number is the
    place.

    Places, not W/L letters. The season table's Wins column counts weekly
    WINS, so a "W" meaning top half would sit two rows above a 0 and
    contradict it.

    Called TRENDS, not "form": form is a soccer word. Not anchors either,
    unlike the team `trend-pills`: a week has no page of its own to link to.

    Renders nothing with no weeks. A week with no field was already left out
    upstream; nothing here fills the gap.
--}}
@if ($weeks !== [])
    <div {{ $attributes->class(['flex items-center gap-2']) }} data-week-trend>
        <p class="text-micro font-medium uppercase tracking-wide text-blue-700/80 dark:text-blue-300/80">Last {{ count($weeks) }}</p>

        <ol class="flex items-center gap-1">
            @foreach ($weeks as $week)
                @php
                    $place = ($week['tied'] ? 'T-' : '').\App\Support\Ordinal::of($week['place']).' of '.$week['field'];
                    $label = \Carbon\CarbonImmutable::parse($week['saturday'])->format('M j').' · '.$place.' · '.($week['top_half'] ? 'top half' : 'bottom half');
                @endphp

                <li
                    wire:key="week-trend-{{ $week['saturday'] }}"
                    title="{{ $label }}"
                    data-top-half="{{ $week['top_half'] ? 'true' : 'false' }}"
                    @class([
                        'tabular flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-micro font-bold',
                        'bg-emerald-500/15 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-400' => $week['top_half'],
                        'bg-red-500/10 text-red-600 dark:bg-red-400/15 dark:text-red-400' => ! $week['top_half'],
                    ])
                ><span aria-hidden="true">{{ $week['place'] }}</span><span class="sr-only">{{ $label }}</span></li>
            @endforeach
        </ol>
    </div>
@endif
