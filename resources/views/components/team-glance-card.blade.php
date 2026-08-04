@props(['glance'])

@php
    $team = $glance['team'];
    $live = $glance['live'];
    $next = $glance['next'];
    $last = $glance['last'];

    $recordLine = collect([
        $glance['record'] ? $glance['record']['overall'].' ('.$glance['record']['conference'].')' : null,
        $glance['position'] !== null && $glance['conference']
            ? Illuminate\Support\Number::ordinal($glance['position']).' in '.$glance['conference']
            : $glance['conference'],
    ])->filter()->implode(' · ');

    $tz = config('cfb.timezone');
@endphp

<div
    {{ $attributes->class(['overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800']) }}
    @style([
        '--team-accent: '.$team->accentColor() => $team->accentColor(),
        '--team-accent-contrast: white' => $team->accentColor(),
    ])
>
    {{-- The header is the card's link to the team page. The whole card cannot
         be one, because the form pills and game lines are links themselves and
         anchors do not nest. --}}
    <a href="{{ route('team', $team) }}" wire:navigate class="team-accent flex items-center gap-3 px-4 py-3">
        <x-team-logo :team="$team" size="lg" class="drop-shadow" />

        <span class="min-w-0 flex-1">
            <span class="flex min-w-0 items-baseline gap-1.5">
                @if ($glance['rank'])
                    <span class="tabular shrink-0 text-sm font-bold opacity-75">{{ $glance['rank'] }}</span>
                @endif
                <span class="truncate text-lg font-bold leading-tight">{{ $team->placeName() }}</span>
            </span>

            @if ($recordLine !== '')
                <span class="block truncate text-sm opacity-90">{{ $recordLine }}</span>
            @endif
        </span>

        <flux:icon name="chevron-right" variant="micro" class="shrink-0 opacity-60" />
    </a>

    <div class="flex flex-col gap-2.5 px-4 py-3">
        @if ($glance['form']->isNotEmpty())
            <div class="flex items-center justify-between gap-2">
                <x-form-pills :games="$glance['form']" :team-id="$team->id" />

                @if ($glance['record']['streak'] ?? null)
                    <span class="text-micro font-medium text-zinc-500">{{ $glance['record']['streak'] }} streak</span>
                @endif
            </div>
        @endif

        @if ($live)
            @php
                $opponent = $live->home_team_id === $team->id ? $live->awayTeam : $live->homeTeam;
                $ownScore = $live->home_team_id === $team->id ? $live->home_score : $live->away_score;
                $oppScore = $live->home_team_id === $team->id ? $live->away_score : $live->home_score;
            @endphp

            <a href="{{ route('game', $live) }}" wire:navigate class="flex items-center gap-2 text-sm" wire:key="glance-live-{{ $live->id }}">
                <span class="flex items-center gap-1 text-micro font-semibold text-red-600 dark:text-red-400">
                    <span class="relative flex size-1.5">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75 motion-reduce:hidden"></span>
                        <span class="relative inline-flex size-1.5 rounded-full bg-red-500"></span>
                    </span>
                    LIVE
                </span>
                @if ($opponent)
                    <x-team-logo :team="$opponent" size="xs" />
                @endif
                <span class="min-w-0 flex-1 truncate">
                    {{ $live->home_team_id === $team->id ? 'vs' : 'at' }} {{ $opponent?->placeName() ?? 'TBD' }}
                </span>
                <span class="tabular shrink-0 font-semibold">{{ $ownScore }}-{{ $oppScore }}</span>
                @if ($live->status_detail)
                    <span class="shrink-0 text-micro text-zinc-500">{{ $live->status_detail }}</span>
                @endif
            </a>
        @elseif ($next)
            @php $opponent = $next->home_team_id === $team->id ? $next->awayTeam : $next->homeTeam; @endphp

            <a href="{{ route('game', $next) }}" wire:navigate class="flex items-center gap-2 text-sm" wire:key="glance-next-{{ $next->id }}">
                <span class="w-9 shrink-0 text-micro font-medium uppercase tracking-wide text-zinc-400">Next</span>
                @if ($opponent)
                    <x-team-logo :team="$opponent" size="xs" />
                @endif
                <span class="min-w-0 flex-1 truncate">
                    {{ $next->home_team_id === $team->id ? 'vs' : 'at' }} {{ $opponent?->placeName() ?? 'TBD' }}
                </span>
                <span class="shrink-0 text-micro text-zinc-500">
                    {{ $next->kickoff_at?->setTimezone($tz)->format('D, M j · g:ia') }}
                </span>
            </a>
        @endif

        @if ($last)
            @php
                $opponent = $last->home_team_id === $team->id ? $last->awayTeam : $last->homeTeam;
                $ownScore = $last->home_team_id === $team->id ? $last->home_score : $last->away_score;
                $oppScore = $last->home_team_id === $team->id ? $last->away_score : $last->home_score;
                $letter = $last->isTie() ? 'T' : ($last->winnerTeamId() === $team->id ? 'W' : 'L');
            @endphp

            <a href="{{ route('game', $last) }}" wire:navigate class="flex items-center gap-2 text-sm" wire:key="glance-last-{{ $last->id }}">
                <span class="w-9 shrink-0 text-micro font-medium uppercase tracking-wide text-zinc-400">Last</span>
                @if ($opponent)
                    <x-team-logo :team="$opponent" size="xs" />
                @endif
                <span class="min-w-0 flex-1 truncate">
                    {{ $last->home_team_id === $team->id ? 'vs' : 'at' }} {{ $opponent?->placeName() ?? 'TBD' }}
                </span>
                <span @class([
                    'tabular shrink-0 font-semibold',
                    'text-emerald-700 dark:text-emerald-400' => $letter === 'W',
                    'text-red-600 dark:text-red-400' => $letter === 'L',
                ])>{{ $letter }} {{ $ownScore }}-{{ $oppScore }}</span>
            </a>
        @endif

        @if ($glance['form']->isEmpty() && ! $live && ! $next && ! $last)
            <span class="text-sm text-zinc-500">No games on the schedule yet.</span>
        @endif
    </div>
</div>
