@props(['games', 'teamId'])

{{--
    A team's recent results as W/L/T pills, OLDEST first so the row reads
    left-to-right toward now, the way soccer form is written. Each pill links
    to its game, which is why this cannot sit inside another anchor.
--}}
<span {{ $attributes->class(['flex items-center gap-1']) }}>
    @foreach ($games as $game)
        @php
            $letter = $game->isTie() ? 'T' : ($game->winnerTeamId() === $teamId ? 'W' : 'L');
            $ownScore = $game->home_team_id === $teamId ? $game->home_score : $game->away_score;
            $oppScore = $game->home_team_id === $teamId ? $game->away_score : $game->home_score;
            $opponent = $game->home_team_id === $teamId ? $game->awayTeam : $game->homeTeam;
        @endphp

        <a
            href="{{ route('game', $game) }}"
            wire:navigate
            wire:key="form-{{ $teamId }}-{{ $game->id }}"
            title="{{ collect([$letter.' '.$ownScore.'-'.$oppScore, $opponent?->placeName()])->filter()->implode(' · ') }}"
            @class([
                'flex size-5 items-center justify-center rounded-full text-micro font-bold transition-transform hover:scale-110',
                'bg-emerald-500/15 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-400' => $letter === 'W',
                'bg-red-500/10 text-red-600 dark:bg-red-400/15 dark:text-red-400' => $letter === 'L',
                'bg-zinc-500/10 text-zinc-600 dark:bg-zinc-400/15 dark:text-zinc-400' => $letter === 'T',
            ])
        >{{ $letter }}</a>
    @endforeach
</span>
