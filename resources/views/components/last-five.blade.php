@props(['team', 'games'])

{{--
    One team's last five results, newest first — the shape a reader scans to
    answer "who is playing better", which trend pills could only hint at.

    `games` arrives OLDEST first, because that is the order the pills want
    (left to right, toward now) and they are still used on Home. A table
    reads the other way, so it is reversed here rather than in the query.
--}}
@php
    $rows = collect($games)->reverse()->values();
@endphp

<div {{ $attributes->class(['flex min-w-0 flex-col']) }}>
    <div class="flex items-center gap-2 border-b border-zinc-200 pb-2 dark:border-zinc-800">
        <x-team-logo :team="$team" size="md" class="shrink-0" />
        <span class="truncate text-sm font-semibold">{{ $team?->placeName() ?? 'TBD' }}</span>
    </div>

    @if ($rows->isEmpty())
        <p class="py-4 text-center text-micro text-zinc-500">No games played yet.</p>
    @else
        <table class="w-full text-stat whitespace-nowrap">
            <thead>
                <tr class="text-micro text-zinc-400">
                    <th class="py-1.5 pe-2 text-left font-medium">Date</th>
                    <th class="py-1.5 text-left font-medium">Opp</th>
                    <th class="py-1.5 text-right font-medium">Result</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                @foreach ($rows as $row)
                    @php
                        $atHome = $row->home_team_id === $team?->id;
                        $opponent = $atHome ? $row->awayTeam : $row->homeTeam;
                        $own = $atHome ? $row->home_score : $row->away_score;
                        $their = $atHome ? $row->away_score : $row->home_score;
                        $letter = $row->isTie() ? 'T' : ($row->winnerTeamId() === $team?->id ? 'W' : 'L');
                    @endphp

                    <tr wire:key="l5-{{ $team?->id }}-{{ $row->id }}">
                        <td class="py-2 pe-2 text-zinc-500">
                            <a href="{{ route('game', $row) }}" wire:navigate class="hover:underline">
                                {{ $row->kickoff_at->setTimezone(config('cfb.timezone'))->format('n/j/y') }}
                            </a>
                        </td>

                        {{-- w-full max-w-0 so the cell is TOLD its size rather
                             than asking for it — a td ignores min-w-0, and the
                             abbreviation would otherwise set the column width. --}}
                        <td class="w-full max-w-0 py-2">
                            <span class="flex items-center gap-1.5">
                                <span class="shrink-0 text-zinc-400">{{ $atHome ? 'vs' : '@' }}</span>
                                <x-team-logo :team="$opponent" size="xs" class="shrink-0" />
                                <span class="truncate font-medium">{{ $opponent?->abbreviation ?? 'TBD' }}</span>
                            </span>
                        </td>

                        <td class="py-2 text-right">
                            <span @class([
                                'font-semibold',
                                'text-emerald-600 dark:text-emerald-400' => $letter === 'W',
                                'text-red-600 dark:text-red-400' => $letter === 'L',
                                'text-zinc-500' => $letter === 'T',
                            ])>{{ $letter }}</span>
                            <span class="tabular font-medium">{{ $own }}-{{ $their }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($team)
        <a
            href="{{ route('team', $team) }}"
            wire:navigate
            class="border-t border-zinc-100 pt-2 text-center text-micro font-medium text-[var(--color-accent-content)] hover:underline dark:border-zinc-800/60"
        >Full Schedule</a>
    @endif
</div>
