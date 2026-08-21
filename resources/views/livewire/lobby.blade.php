<?php

use App\Actions\JoinGroup;
use App\Enums\ContestMode;
use App\Exceptions\ContestFull;
use App\Exceptions\PickemParticipationGated;
use App\Models\Contest;
use App\Models\Group;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\TeamSeason;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Cadence;
use App\Support\LobbyCatalog;
use App\Support\RankLadder;
use App\Support\Voice;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * THE LOBBY, pass 3 — where the Picks tab lands, one zoned scroll ordered
 * by urgency: the week's dateline, the slates that still need your picks,
 * your games, last week's payoff, the doors to a new game, and the rules
 * of every mode. Outside the `pickem` flag it keeps the coming-soon
 * promise the tab shipped with, verbatim.
 *
 * One query per CONCERN across all groups, never per card: contests,
 * slates, my picks, my entries, my weekly wins — each is a single read
 * however many groups the reader belongs to. The zones above are pure
 * PROJECTIONS of that one cards() read, never a second query.
 */
new class extends Component
{
    public string $code = '';

    #[Computed]
    public function showLobby(): bool
    {
        return auth()->check() && Feature::active('pickem');
    }

    /**
     * The reader's rung, and the climb to the next one.
     *
     * The header chip has room for the NAME and nothing else, so this is the
     * only surface where the next rung is named — which is what stops
     * "Captain" from being a word with no scale behind it. No extra query:
     * walletTotals() is memoized per request and the ladder is arithmetic.
     *
     * @return array{name: string, floor: int, next: string|null, at: int|null, remaining: int|null, progress: float}|null
     */
    #[Computed]
    public function rank(): ?array
    {
        return auth()->check()
            ? RankLadder::for($this->walletXp)
            : null;
    }

    #[Computed]
    public function walletXp(): int
    {
        return auth()->check() ? auth()->user()->walletTotals()['xp'] : 0;
    }

    /**
     * Every group card's state, assembled flat.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function cards()
    {
        $groups = auth()->user()->groups()->withCount('memberships')->orderBy('name')->get();

        if ($groups->isEmpty()) {
            return collect();
        }

        $contests = Contest::query()
            ->whereIn('group_id', $groups->pluck('id'))
            ->where('season_year', app(CfbCalendar::class)->currentYear())
            ->get()
            ->keyBy('group_id');

        $weekId = $contests->isEmpty()
            ? null
            : app(CfbCalendar::class)->defaultWeekId($contests->first()->season_year);

        $slates = $weekId === null ? collect() : Slate::query()
            ->whereIn('contest_id', $contests->pluck('id'))
            ->where('week_id', $weekId)
            ->with('games.game:id,kickoff_at,status,completed')
            ->get()
            ->keyBy('contest_id');

        // My tallies, one aggregate each: picks made + live points per
        // slate, my entry per slate, my weekly wins per contest.
        $made = Pick::query()
            ->join('slate_games', 'slate_games.id', '=', 'picks.slate_game_id')
            ->whereIn('slate_games.slate_id', $slates->pluck('id'))
            ->where('picks.user_id', auth()->id())
            ->groupBy('slate_games.slate_id')
            ->selectRaw('slate_games.slate_id, COUNT(*) AS made, COALESCE(SUM(picks.points), 0) AS pts')
            ->get()
            ->keyBy('slate_id');

        $entries = SlateEntry::query()
            ->whereIn('slate_id', $slates->pluck('id'))
            ->where('user_id', auth()->id())
            ->get()
            ->keyBy('slate_id');

        $wins = SlateEntry::query()
            ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
            ->whereIn('slates.contest_id', $contests->pluck('id'))
            ->where('slates.status', Slate::SETTLED)
            ->where('slate_entries.user_id', auth()->id())
            ->groupBy('slates.contest_id')
            ->selectRaw('slates.contest_id, COALESCE(SUM(slate_entries.won), 0) AS wins')
            ->pluck('wins', 'contest_id');

        $week = $weekId === null ? null : Week::find($weekId);
        $deadline = $week === null ? null : Cadence::slateDeadline($week);

        return $groups->map(function (Group $group) use ($contests, $slates, $made, $entries, $wins, $deadline) {
            $contest = $contests->get($group->id);
            $slate = $contest === null ? null : $slates->get($contest->id);
            $tally = $slate === null ? null : $made->get($slate->id);
            $entry = $slate === null ? null : $entries->get($slate->id);

            $state = match (true) {
                $slate === null || $slate->status === Slate::DRAFT => 'waiting',
                $slate->status === Slate::SETTLED => 'final',
                $slate->status === Slate::PRELIM => 'prelim',
                $slate->games->contains(fn ($slateGame) => $slateGame->game->hasKickedOff()) => 'live',
                default => 'upcoming',
            };

            return [
                'group' => $group,
                'contest' => $contest,
                'commissioner' => $group->pivot->role === App\Models\GroupMember::COMMISSIONER,
                'state' => $state,
                'made' => (int) ($tally->made ?? 0),
                'total' => $slate?->games->count() ?? 0,
                'points' => $state === 'final'
                    ? (int) ($entry->final_points ?? 0)
                    : (int) ($tally->pts ?? 0),
                'won' => (bool) ($entry->won ?? false),
                'wins' => (int) ($wins[$contest?->id] ?? 0),
                'firstKick' => $slate?->games->map(fn ($slateGame) => $slateGame->game->kickoff_at)->filter()->min(),
                'deadline' => $deadline,
            ];
        });
    }

    /**
     * The zone that answers "what do I do right now": published slates
     * still taking picks where mine are not all in. A pure projection of
     * cards() — no query of its own.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function needsPicks()
    {
        return $this->cards
            ->filter(fn (array $card) => in_array($card['state'], ['upcoming', 'live'], true)
                && $card['total'] > 0
                && $card['made'] < $card['total'])
            ->values();
    }

    /**
     * The week's dateline entry from the calendar. Null skips the ribbon
     * entirely — never a substituted week.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function weekEntry(): ?array
    {
        return app(CfbCalendar::class)->defaultWeekEntry(app(CfbCalendar::class)->currentYear());
    }

    /**
     * The ribbon's one clock line, by urgency: games live now beats the
     * next kickoff beats a commissioner's slate deadline. Null when none
     * of it applies — the ribbon then carries the dateline alone.
     *
     * @return array{type: string, at: \Carbon\CarbonInterface|null}|null
     */
    #[Computed]
    public function ribbonClock(): ?array
    {
        $cards = $this->cards;

        if ($cards->contains(fn (array $card) => $card['state'] === 'live')) {
            return ['type' => 'live', 'at' => null];
        }

        $kick = $cards
            ->filter(fn (array $card) => $card['state'] === 'upcoming')
            ->pluck('firstKick')
            ->filter()
            ->min();

        if ($kick !== null) {
            return ['type' => 'kick', 'at' => $kick];
        }

        $waiting = $cards->first(fn (array $card) => $card['state'] === 'waiting'
            && $card['commissioner']
            && $card['deadline'] !== null);

        return $waiting === null ? null : ['type' => 'deadline', 'at' => $waiting['deadline']];
    }

    /**
     * The Monday payoff: my settled weeks from the last seven days.
     *
     * @return \Illuminate\Support\Collection<int, SlateEntry>
     */
    #[Computed]
    public function lastWeek()
    {
        return SlateEntry::query()
            ->where('user_id', auth()->id())
            ->whereHas('slate', fn ($q) => $q
                ->where('status', Slate::SETTLED)
                ->where('settled_at', '>=', now()->subDays(7)))
            ->with('slate.contest.group:id,name,kind,week_id')
            ->get();
    }

    /**
     * The public inventory: open transient rooms for the current week
     * (seats free, slate published, week unsettled) plus any evergreen
     * house lobby. A filled room never shows without its successor — the
     * join hook spawns Room N+1 the instant Room N fills.
     */
    #[Computed]
    public function publics()
    {
        $weekId = app(CfbCalendar::class)->defaultWeekId(app(CfbCalendar::class)->currentYear());

        // The lobby sells ONE Saturday at a time — inside a split opening
        // week, 8/29's rooms and 9/5's must never share it.
        $week = $weekId === null ? null : Week::find($weekId);
        $target = $week === null ? null : Cadence::activeSaturday($week)?->toDateString();

        return Group::query()
            ->where('kind', Group::KIND_LOBBY)
            ->whereDoesntHave('memberships', fn ($q) => $q->where('user_id', auth()->id()))
            ->where(fn ($q) => $q
                ->whereNull('week_id')
                ->when($weekId !== null, fn ($qq) => $qq->orWhere(fn ($room) => $room
                    ->where('week_id', $weekId)
                    ->whereNull('filled_at'))))
            ->withCount('memberships')
            ->with(['contests.slates' => fn ($q) => $q
                ->select('id', 'contest_id', 'week_id', 'status', 'saturday')
                ->withCount('games')])
            ->get()
            ->filter(function (Group $group) use ($target) {
                if (! $group->isRoom()) {
                    return true;
                }

                if ($group->member_cap !== null && $group->memberships_count >= $group->member_cap) {
                    return false;
                }

                // Open means PICKABLE: this Saturday's slate is out and
                // not yet settled away.
                return $group->contests->first()
                    ?->slates->contains(fn ($slate) => $slate->week_id === $group->week_id
                        && $slate->status === Slate::PUBLISHED
                        && ($target === null || $slate->saturday?->toDateString() === $target)) ?? false;
            })
            // Catalog order, not alphabetical — the standard rooms lead,
            // the specialty shelf follows, and the viewer's own conference
            // fronts the conference family.
            ->sortBy(fn (Group $group) => LobbyCatalog::sortKey($group, $this->viewerConference()))
            ->values();
    }

    /**
     * The conference of the viewer's FIRST followed team this season —
     * the room the conference family leads with, for them. Null for
     * guests and the unaffiliated: catalog order.
     */
    private function viewerConference(): ?string
    {
        $teamId = auth()->user()?->followedTeams()
            ->orderBy('team_follows.position')
            ->value('teams.id');

        if ($teamId === null) {
            return null;
        }

        return TeamSeason::query()
            ->where('team_id', $teamId)
            ->where('season_year', app(CfbCalendar::class)->currentYear())
            ->join('conferences', 'conferences.id', '=', 'team_seasons.conference_id')
            ->value('conferences.abbreviation');
    }

    public function join(JoinGroup $action)
    {
        $code = strtoupper(trim($this->code));

        $group = $code === '' ? null : Group::query()->where('code', $code)->first();

        if ($group === null) {
            $this->addError('code', Voice::line('groups.join.bad_code'));

            return;
        }

        return $this->takeSeat($action, $group, 'code');
    }

    public function joinLobby(int $groupId, JoinGroup $action)
    {
        $lobby = Group::query()->where('kind', Group::KIND_LOBBY)->findOrFail($groupId);

        return $this->takeSeat($action, $lobby, 'lobbies');
    }

    private function takeSeat(JoinGroup $action, Group $group, string $errorBag)
    {
        try {
            $action->handle(auth()->user(), $group);
        } catch (PickemParticipationGated) {
            $this->addError($errorBag, Voice::line('groups.verify_first'));

            return;
        } catch (ContestFull) {
            // A race to the last seat: the lobby re-renders without the
            // filled room, and the words say why.
            $this->addError($errorBag, Voice::line('contest.room.full'));
            unset($this->publics);

            return;
        }

        session()->flash('status', Voice::line('groups.joined', ['group' => $group->name]));

        // Each kind lands at its own address — no clubhouse double-hop.
        return $this->redirectRoute(
            $group->isRoom() ? 'pickem.room' : 'pickem.group',
            $group,
            navigate: true,
        );
    }
}; ?>

<div class="flex flex-col gap-6 lg:mx-auto lg:w-full lg:max-w-3xl">
    @if ($this->showLobby)
        {{-- =============================== THE LOBBY ================= --}}
        {{-- The section strip names this place now — the h1 stays for
             screen readers only, the house rule. --}}
        <h1 class="sr-only">Lobby</h1>

        <x-verify-email-callout :body-key="'verify.picks.body'" :dismissable="false" />

        @if (session('status'))
            <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                {{ session('status') }}
            </div>
        @endif

        {{-- ZONE 1 · The week's dateline. No calendar entry, no ribbon —
             never a substituted week. --}}
        @if ($this->weekEntry !== null)
            <x-week-ribbon :entry="$this->weekEntry" :clock="$this->ribbonClock" />
        @endif

        {{-- ZONE 2 · What needs you right now: slates still taking your
             picks, each row walking straight into its clubhouse. --}}
        @if ($this->needsPicks->isNotEmpty())
            <div class="flex flex-col gap-2">
                <flux:heading size="lg">Needs your picks</flux:heading>
                <flux:subheading>{{ Voice::line('lobby.needs.subheading') }}</flux:subheading>

                @foreach ($this->needsPicks as $card)
                    <a
                        href="{{ $card['group']->isRoom() ? route('pickem.room', $card['group']) : route('pickem.group', $card['group']) }}"
                        wire:navigate
                        wire:key="needs-{{ $card['group']->id }}"
                        class="flex items-center justify-between gap-3 rounded-xl border border-blue-200 bg-blue-50/50 px-4 py-3 hover:border-blue-300 dark:border-blue-900 dark:bg-blue-950/20 dark:hover:border-blue-800"
                    >
                        <span class="min-w-0">
                            <span class="block truncate font-semibold leading-tight">{{ $card['group']->name }}</span>
                            <x-slate-progress :made="$card['made']" :total="$card['total']" class="pt-1" />
                        </span>
                        <span class="flex shrink-0 items-center gap-1.5 text-micro text-zinc-500">
                            @if ($card['firstKick'])
                                kicks {{ $card['firstKick']->setTimezone(config('cfb.timezone'))->format('D g:ia') }}
                            @endif
                            <flux:icon name="chevron-right" variant="micro" class="text-zinc-400" />
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- ZONE 3 · Your games — or, before the first one, the pitch. --}}
        @if ($this->cards->isNotEmpty())
            <div class="flex flex-col gap-2">
                <flux:heading size="lg">Your games</flux:heading>

                @foreach ($this->cards as $card)
                    <x-group-card wire:key="lobby-group-{{ $card['group']->id }}" :card="$card" />
                @endforeach
            </div>
        @else
            {{-- FIRST RUN: sell the game. Three doors, each wearing its
                 own colors, all walking into the creation wizard. --}}
            <div class="flex flex-col gap-2">
                <flux:heading size="lg">Pick your game</flux:heading>
                <flux:subheading>{{ Voice::line('lobby.first_run.body') }}</flux:subheading>

                <div class="flex flex-col gap-2 pt-1">
                    @foreach (ContestMode::cases() as $mode)
                        <x-mode-door wire:key="door-{{ $mode->value }}" :mode="$mode" />
                    @endforeach
                </div>

                <p class="pt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Rather not run one? Grab a seat in an open room below.
                </p>
            </div>
        @endif

        {{-- ZONE 4 · The Monday payoff, compact: last week's settled
             results while they are still the conversation. --}}
        @if ($this->lastWeek->isNotEmpty())
            <div class="flex flex-col gap-2">
                <flux:heading size="lg">Last week</flux:heading>
                @foreach ($this->lastWeek as $entry)
                    <a
                        href="{{ $entry->slate->contest->group->isRoom() ? route('pickem.room', $entry->slate->contest->group_id) : route('pickem.group', $entry->slate->contest->group_id) }}"
                        wire:navigate
                        wire:key="settled-{{ $entry->id }}"
                        class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-2.5 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
                    >
                        <p class="min-w-0 truncate text-sm font-medium">{{ $entry->slate->contest->group->name }}</p>
                        <p class="flex shrink-0 items-center gap-2 text-sm">
                            <span class="tabular font-semibold">{{ $entry->final_points ?? 0 }} pts</span>
                            @if ($entry->won)
                                <flux:badge size="sm" color="green">Winner</flux:badge>
                            @endif
                        </p>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- ZONE 4b · THE LADDER. The header chip has room for the rung and
             nothing else, so this is where the next one is named and the
             climb has a number on it. Placed after the payoff and before the
             doors: it reads as what last week bought you. --}}
        @if ($this->rank !== null)
            <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-baseline justify-between gap-3">
                    <flux:heading size="lg" class="min-w-0 truncate">{{ $this->rank['name'] }}</flux:heading>
                    <span class="tabular shrink-0 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ number_format($this->walletXp) }} XP
                    </span>
                </div>

                @if ($this->rank['next'] !== null)
                    {{-- A share of the CURRENT rung's span, so the bar resets
                         at each promotion instead of creeping toward Legend
                         all season. --}}
                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <div
                            class="h-full rounded-full bg-zinc-900 dark:bg-zinc-100"
                            style="width: {{ round($this->rank['progress'] * 100, 2) }}%"
                        ></div>
                    </div>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ Voice::line('rank.to_next', [
                            'remaining' => number_format($this->rank['remaining']),
                            'next' => $this->rank['next'],
                        ]) }}
                    </p>
                @else
                    {{-- No next rung. `remaining` is null here, never a zero
                         standing in for it — so the climb line is SKIPPED
                         rather than rendered as a finished bar with no name. --}}
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('rank.topped_out') }}</p>
                @endif
            </div>
        @endif

        {{-- ZONE 5 · Find a contest: the lobby, the start-a-group
             door, and the code form folded away as the secondary path. --}}
        <div class="flex flex-col gap-3">
            <flux:heading size="lg">Find a game</flux:heading>
            <flux:subheading>{{ Voice::line('groups.lobbies.subheading') }}</flux:subheading>
            @error('lobbies')
                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            @forelse ($this->publics as $lobby)
                @if ($lobby->isRoom() && $lobby->contests->first() !== null)
                    @php
                        $floorSlate = $lobby->contests->first()->slates
                            ->first(fn ($slate) => $slate->week_id === $lobby->week_id && $slate->status === Slate::PUBLISHED);
                    @endphp

                    <x-contest-card
                        wire:key="lobby-{{ $lobby->id }}"
                        :room="$lobby"
                        :mode="$lobby->contests->first()->mode"
                        :seats="$lobby->memberships_count"
                        :flavor="$lobby->flavorEnum()"
                        :game-count="$floorSlate?->games_count"
                    />
                @else
                    <div
                        wire:key="lobby-{{ $lobby->id }}"
                        class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ $lobby->name }}</p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $lobby->memberships_count }} {{ Str::plural('member', $lobby->memberships_count) }}
                            </p>
                        </div>
                        <flux:button wire:click="joinLobby({{ $lobby->id }})" size="sm" class="shrink-0">Join</flux:button>
                    </div>
                @endif
            @empty
                <flux:callout icon="user-group">
                    <flux:callout.heading>No open rooms right now</flux:callout.heading>
                    <flux:callout.text>{{ Voice::line('lobby.publics.empty') }}</flux:callout.text>
                </flux:callout>
            @endforelse

            <a
                href="{{ route('pickem.create') }}"
                wire:navigate
                class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 p-4 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
            >
                <div class="min-w-0">
                    <p class="font-semibold">Start a group</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Name it, pick its game, send one link.</p>
                </div>
                <span class="flex shrink-0 items-center gap-1.5">
                    @foreach (ContestMode::cases() as $mode)
                        <span wire:key="door-hint-{{ $mode->value }}" class="flex size-6 items-center justify-center rounded-md border {{ $mode->palette()['tile'] }}">
                            <flux:icon :name="$mode->icon()" variant="micro" class="size-3.5 {{ $mode->palette()['icon'] }}" />
                        </span>
                    @endforeach
                    <flux:icon name="chevron-right" variant="micro" class="text-zinc-400" />
                </span>
            </a>

            {{-- The code stays as the spoken-word fallback, folded away —
                 links are how a group travels now. --}}
            <div
                x-data="{ open: @js($errors->has('code')) }"
                class="rounded-xl border border-zinc-200 dark:border-zinc-700"
            >
                <button
                    type="button"
                    x-on:click="open = ! open"
                    x-bind:aria-expanded="open"
                    class="flex w-full items-center justify-between gap-3 p-4 text-start"
                >
                    <div class="min-w-0">
                        <p class="font-semibold">Have an invite code?</p>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('groups.join.subheading') }}</p>
                    </div>
                    <flux:icon name="chevron-down" variant="micro" class="shrink-0 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-180'" />
                </button>

                <div x-show="open" x-cloak class="border-t border-zinc-100 p-4 dark:border-zinc-800/60">
                    <form wire:submit="join" class="flex flex-col gap-3">
                        {{-- The format rule stays plain: 8 characters, told straight. --}}
                        <flux:input wire:model="code" label="Invite code" description="The 8-character code from your group." maxlength="8" autocomplete="off" class="uppercase" />
                        <flux:button type="submit" variant="primary" class="self-start">Join the group</flux:button>
                    </form>
                </div>
            </div>
        </div>

        {{-- ZONE 6 · The rules, one expandable card per mode — the same
             ruleLines() the docs and the mode doors read. --}}
        <div class="flex flex-col gap-2">
            <flux:heading size="lg">How it's played</flux:heading>
            <flux:subheading>{{ Voice::line('lobby.rules.subheading') }}</flux:subheading>

            <div class="flex flex-col gap-2 pt-1">
                @foreach (ContestMode::cases() as $mode)
                    <x-mode-rules wire:key="rules-{{ $mode->value }}" :mode="$mode" />
                @endforeach
            </div>

            {{-- The rules every mode shares, stated once and plainly. --}}
            <p class="pt-1 text-micro leading-relaxed text-zinc-500">
                Every pick is against the spread, and every line is a half point — no pushes, ever.
                Picks lock game by game at kickoff. Commissioner slates are due Tuesday night;
                weeks turn official Sunday noon. Tied weeks share the win.
            </p>
        </div>
    @else
        {{-- ===================== THE COMING-SOON PROMISE ============= --}}
        {{-- Everything outside the flag keeps the front door exactly as
             it shipped: a promise, not data. --}}
        <div class="flex items-center gap-2">
            <x-brand.mark class="size-6 shrink-0 sm:hidden" />
            <flux:heading size="xl">Pick'em</flux:heading>
            <flux:badge size="sm" color="zinc">Coming soon</flux:badge>
        </div>

        <flux:subheading>{{ Voice::line('picks.screen.pitch') }}</flux:subheading>

        <x-verify-email-callout :body-key="'verify.picks.body'" :dismissable="false" />

        <div class="flex flex-col gap-3">
            <div class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700">
                <div class="flex items-center gap-2">
                    <flux:icon name="clipboard-document-check" variant="mini" class="text-zinc-400" />
                    <span class="font-semibold">Weekly slates</span>
                </div>
                <p class="pt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    A week's games, your calls, locked at kickoff — every pick against the spread.
                </p>
            </div>

            <div class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700">
                <div class="flex items-center gap-2">
                    <flux:icon name="user-group" variant="mini" class="text-zinc-400" />
                    <span class="font-semibold">Groups</span>
                </div>
                <p class="pt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    A private leaderboard for your people — invites, standings, and a commissioner.
                </p>
            </div>

            <div class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700">
                <div class="flex items-center gap-2">
                    <flux:icon name="chart-bar" variant="mini" class="text-zinc-400" />
                    <span class="font-semibold">Season-long records</span>
                </div>
                <p class="pt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Every pick kept, every week counted — a full season of results by the end.
                </p>
            </div>
        </div>

        <p class="text-stat text-zinc-500 dark:text-zinc-400">
            Until then, <a href="{{ route('home') }}" wire:navigate class="font-medium text-blue-600 hover:underline dark:text-blue-400">follow your teams</a> — your picks will start from the teams you already watch.
        </p>
    @endif
</div>
