{{--
    Where College GameDay is broadcasting from this Saturday.

    ONE FIXED SLOT in every state, so nothing below it reflows between a week
    that has been announced and one that has not — the empty state is a real
    state here, not an absence. Off-season the component renders nothing at
    all: a dead card for seven months is clutter, not presence.

    The LOCATION is a fact and is written plainly. The framing around it is
    loud, and gets louder when the bus is parked on a campus the reader
    follows, which is when the card also takes that team's palette — a
    personal event rather than a league headline.
--}}
@php
    use App\Support\Gameday;
    use App\Support\Voice;

    $week = Gameday::current();
    $known = $week?->status->isKnown() ?? false;
    $game = $known ? $week->game : null;
    $team = $known ? $week->team : null;

    // Only a followed campus earns the color. Everyone else's week is news.
    $yours = Gameday::isFollowed($week, auth()->user());
    $palette = $yours ? $team?->palette() : null;

    // The show's own shield, pinned in config the way the feed path is —
    // never fetched at render. Empty is a real state and gets the tv glyph
    // the card wore before: chrome, not a stand-in for the mark.
    $logo = config('gameday.logo_url');
    $logo = is_string($logo) && trim($logo) !== '' ? trim($logo) : null;

    $line = match (true) {
        ! $known => Voice::line('home.gameday.unknown'),
        $yours && $team !== null => Voice::line('home.gameday.yours', ['team' => $team->placeName()]),
        default => Voice::line('home.gameday', ['city' => $week->city ?? 'the site']),
    };
@endphp

@if (Gameday::renders())
    {{-- An anchor only when there is somewhere to go. An unannounced week is
         a card with nothing behind it, and a link to nothing is a broken
         promise the second somebody taps it. --}}
    <{{ $game ? 'a' : 'div' }}
        @if ($game) href="{{ route('game', $game) }}" wire:navigate @endif
        {{ $attributes->class([
            'block rounded-xl border px-4 py-3 transition-colors',
            'border-zinc-200 dark:border-zinc-800' => ! $yours,
            'border-zinc-300 hover:border-zinc-400 hover:bg-zinc-50 dark:hover:border-zinc-600 dark:hover:bg-zinc-900' => $game && ! $yours,
            'team-keyline border-transparent' => $yours,
        ]) }}
        @style([
            '--team-accent: '.$palette?->surface => $palette,
            '--team-accent-contrast: '.$palette?->text => $palette,
            '--team-keyline: '.$team?->altAccentColor() => $yours && $team?->altAccentColor(),
        ])
    >
        {{-- THE SHIELD is the card's mark, on the left of everything the
             card says — the glance-card grammar — so the header row keeps
             its height. It brings its own dark ground and a gray outline,
             so it reads on the white card, the dark one and a followed
             team's keyline alike. --}}
        <div class="flex gap-3">
            @if ($logo)
                <img
                    src="{{ $logo }}"
                    alt=""
                    loading="lazy"
                    decoding="async"
                    class="h-12 w-auto shrink-0 self-center object-contain"
                    data-gameday-mark="true"
                >
            @endif

            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    @unless ($logo)
                        {{-- `tv` rather than a vendored bootstrap `broadcast`: game-info already
                             uses it for the same idea, and Flux ships it. --}}
                        <flux:icon.tv variant="mini" class="shrink-0 text-zinc-400" data-gameday-glyph="true" />
                    @endunless
                    <span class="font-semibold">College GameDay</span>

                    @if ($known)
                        <flux:badge size="sm" :color="$yours ? 'amber' : 'zinc'">
                            {{ $week->saturday->setTimezone(config('cfb.timezone'))->format('M j') }}
                        </flux:badge>
                    @else
                        <flux:badge size="sm" color="zinc">TBA</flux:badge>
                    @endif
                </div>

                @if ($known)
                    {{-- The fact, plainly. A logo never sits on the team's own color,
                         so it rides a neutral puck exactly as the glance cards do. --}}
                    <div class="flex items-center gap-2 pt-2">
                        @if ($team)
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white shadow-sm ring-1 ring-black/10 dark:bg-transparent dark:shadow-none dark:ring-0">
                                <x-team-logo :team="$team" size="size-6" />
                            </span>
                        @endif

                        <div class="min-w-0">
                            <p class="truncate font-bold leading-tight">{{ $week->city }}, {{ $week->state }}</p>

                            @if ($game)
                                <p class="truncate text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $game->name }} · {{ $game->kickoffLabel('date') }}
                                </p>
                            @elseif ($week->site)
                                <p class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $week->site }}</p>
                            @endif
                        </div>
                    </div>
                @endif

                <p class="pt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $line }}</p>
            </div>
        </div>
    </{{ $game ? 'a' : 'div' }}>
@endif
