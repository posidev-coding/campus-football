<?php

use App\Actions\ChangeGroupMode;
use App\Actions\JoinGroup;
use App\Actions\LeaveGroup;
use App\Actions\RemoveGroupMember;
use App\Enums\ContestMode;
use App\Exceptions\ContestFull;
use App\Exceptions\GroupNeedsCommissioner;
use App\Exceptions\ModeChangeBlocked;
use App\Exceptions\NotGroupCommissioner;
use App\Exceptions\PickemParticipationGated;
use App\Livewire\Concerns\MakesPicks;
use App\Models\Contest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use App\Services\CfbCalendar;
use App\Services\Contests\SpreadGrader;
use App\Support\Voice;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * THE CLUBHOUSE — one group's home, rebuilt from the app's own DNA: the
 * hero band up top, a three-tab plate (Slate | Season | Members), and the
 * shared pick surface as the Slate tab, so picking lives one tap inside
 * the room where the trash talk happens.
 *
 * Private groups are members-only; lobbies are readable by anyone signed
 * in, who sees the week's slate as a non-interactive preview behind the
 * join button. Every mutation rides an Action that enforces its own
 * authority — the @if around a button here is presentation, not the gate.
 *
 * Relations live in computeds, never on the model property: Livewire
 * re-hydrates `$group` WITHOUT relations on every request, so a template
 * reading `$group->contests` is a lazy-load 500 waiting for its second
 * request.
 */
new class extends Component
{
    use MakesPicks {
        refreshPicks as refreshPickState;
    }

    /** The palette columns ride every card-feeding load — drop one and the cards silently un-brand. */
    private const TEAM_COLUMNS = 'id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark,color,alt_color,header_style';

    private const VIEWS = ['slate', 'season', 'members'];

    public Group $group;

    #[Url(except: 'slate')]
    public string $view = 'slate';

    /** The pivot modal's chosen target — a ContestMode backing value. */
    public ?string $pivotTo = null;

    public function mount(Group $group): void
    {
        $this->group = $group;
        $this->view = $this->normalizedView($this->view);

        abort_unless($group->isLobby() || $this->seatOf(auth()->user()) !== null, 403);

        // Each kind lives at its own address: a shared link always reads
        // /contests/... for a room and /groups/... for a group. Keyed on
        // the WRONG address specifically, so a component mounted outside
        // any route (a test, an embed) just renders.
        if ($group->isRoom() && request()->routeIs('pickem.group')) {
            $this->redirectRoute('pickem.room', $group, navigate: true);
        } elseif (! $group->isRoom() && request()->routeIs('pickem.room')) {
            $this->redirectRoute('pickem.group', $group, navigate: true);
        }
    }

    /** #[Url] hydrates without firing this hook, hence mount() normalizes too. */
    public function updatedView(string $value): void
    {
        $this->view = $this->normalizedView($value);
    }

    /**
     * The group's contest — the season-long game this room plays.
     *
     * One contest per group per season arrives with the next slice's
     * migration; until the dev-era duplicates collapse, FIELD() fronts the
     * main event deterministically rather than whichever row inserted
     * first.
     */
    #[Computed]
    public function contest(): ?Contest
    {
        return $this->group->contests()
            ->orderByRaw("FIELD(mode, 'tiered', 'classic', 'woodshed')")
            ->first();
    }

    /** This week's slate with everything the pick surface renders. */
    #[Computed]
    public function slate(): ?Slate
    {
        if ($this->contest === null) {
            return null;
        }

        $weekId = app(CfbCalendar::class)->defaultWeekId($this->contest->season_year);

        if ($weekId === null) {
            return null;
        }

        $team = self::TEAM_COLUMNS;

        return Slate::query()
            ->where('contest_id', $this->contest->id)
            ->where('week_id', $weekId)
            ->with([
                "games.game.homeTeam:{$team}",
                "games.game.awayTeam:{$team}",
                "tiebreakerGame.game.homeTeam:{$team}",
                "tiebreakerGame.game.awayTeam:{$team}",
                'tiebreakerTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                'entries.user:id,first_name,last_name,handle',
                'contest.group:id,name,kind',
            ])
            ->first();
    }

    /**
     * Where the week's surface stands, for the badge and the standings
     * table: null while nothing is published, then upcoming → live →
     * prelim → final. Matches the partial's own derivation.
     */
    #[Computed]
    public function surfaceStatus(): ?string
    {
        $slate = $this->slate;

        if ($slate === null || ! $slate->isPublished()) {
            return null;
        }

        return match (true) {
            $slate->status === Slate::SETTLED => 'final',
            $slate->status === Slate::PRELIM => 'prelim',
            $slate->games->contains(fn ($slateGame) => $slateGame->game->hasKickedOff()) => 'live',
            default => 'upcoming',
        };
    }

    /**
     * The week's room, ranked: live totals as one aggregate over picks
     * (the walletTotals() philosophy), the stamped final_points once the
     * week settles. On a Woodshed slate the Bear sits in the table too —
     * a label row ranked like anyone, never a winner (no entry to win
     * with), his running total computed from relations already in memory.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function weekStandings(): Collection
    {
        $slate = $this->slate;

        if ($slate === null || ! $slate->isPublished()) {
            return collect();
        }

        $totals = Pick::query()
            ->join('slate_games', 'slate_games.id', '=', 'picks.slate_game_id')
            ->where('slate_games.slate_id', $slate->id)
            ->groupBy('picks.user_id')
            ->selectRaw('picks.user_id, COALESCE(SUM(picks.points), 0) AS pts')
            ->pluck('pts', 'user_id')
            ->map(fn ($pts) => (int) $pts);

        $rows = $slate->entries
            ->map(fn (SlateEntry $entry) => [
                'user' => $entry->user,
                'label' => null,
                'key' => null,
                'icon' => null,
                'won' => $slate->status === Slate::SETTLED && (bool) $entry->won,
                'points' => $slate->status === Slate::SETTLED
                    ? ($entry->final_points ?? 0)
                    : ($totals[$entry->user_id] ?? 0),
            ]);

        $bearPoints = $this->bearPoints($slate);

        if ($bearPoints !== null) {
            $rows->push([
                'user' => null,
                'label' => 'The Bear',
                'key' => 'bear',
                'icon' => 'paw-print',
                'won' => false,
                'points' => $bearPoints,
            ]);
        }

        return $rows
            ->sortByDesc('points')
            ->values()
            ->map(fn (array $row, int $i) => [
                'rank' => $i + 1,
                'user' => $row['user'],
                'label' => $row['label'],
                'key' => $row['key'],
                'icon' => $row['icon'],
                'won' => $row['won'],
                'cells' => [$row['points']],
            ]);
    }

    /**
     * The Bear's running total on a Woodshed slate — raw tier points over
     * his kicked-off games, the same frozen-line arithmetic as anyone's,
     * computed from the loaded slate with ZERO extra queries. Null when
     * this slate fields no Bear.
     */
    private function bearPoints(Slate $slate): ?int
    {
        if ($slate->bear_theme === null || $this->contest === null) {
            return null;
        }

        $engine = $this->contest->mode->engine($this->contest->settings);

        if (! $engine->hasBear()) {
            return null;
        }

        $grader = app(SpreadGrader::class);

        return (int) $slate->games
            ->filter(fn ($slateGame) => $slateGame->bear_team_id !== null && $slateGame->game->hasKickedOff())
            ->sum(fn ($slateGame) => $grader->resultFor($slateGame, $slateGame->game, $slateGame->bear_team_id) === Pick::WIN
                ? $engine->pointsFor($slateGame)
                : 0);
    }

    /**
     * The season ledger — weekly wins, then total points, with this
     * week's live number riding along. Every member appears: a zero week
     * count is an honest aggregate over no rows, not a substituted value.
     *
     * @return Collection<int, array{rank: int, user: User, won: bool, cells: list<int|string>}>
     */
    #[Computed]
    public function seasonStandings(): Collection
    {
        if ($this->contest === null) {
            return collect();
        }

        $aggregates = SlateEntry::query()
            ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
            ->where('slates.contest_id', $this->contest->id)
            ->where('slates.status', Slate::SETTLED)
            ->groupBy('slate_entries.user_id')
            ->selectRaw('slate_entries.user_id, COALESCE(SUM(slate_entries.won), 0) AS wins, COALESCE(SUM(slate_entries.final_points), 0) AS pts')
            ->get()
            ->keyBy('user_id');

        // The Bear's label row has no user and no season ledger to join.
        $week = $this->weekStandings
            ->filter(fn (array $row) => $row['user'] !== null)
            ->keyBy(fn (array $row) => $row['user']->id);

        return $this->members
            ->map(fn (GroupMember $seat) => [
                'user' => $seat->user,
                'wins' => (int) ($aggregates[$seat->user_id]->wins ?? 0),
                'points' => (int) ($aggregates[$seat->user_id]->pts ?? 0),
                'week' => isset($week[$seat->user_id]) ? $week[$seat->user_id]['cells'][0] : null,
            ])
            ->sortBy([['wins', 'desc'], ['points', 'desc']])
            ->values()
            ->map(fn (array $row, int $i) => [
                'rank' => $i + 1,
                'user' => $row['user'],
                'won' => false,
                'cells' => [$row['wins'], $row['points'], $row['week'] ?? '—'],
            ]);
    }

    /**
     * The modes this group could pivot TO — every live mode but the one
     * it plays. The Woodshed's arrival grew the old single-answer seam
     * into this choice, so the modal is a radiogroup now.
     *
     * @return Collection<int, ContestMode>
     */
    #[Computed]
    public function pivotChoices(): Collection
    {
        if ($this->contest === null) {
            return collect();
        }

        return collect(ContestMode::cases())
            ->filter(fn (ContestMode $mode) => $mode->available() && $mode !== $this->contest->mode)
            ->values();
    }

    #[Computed]
    public function seasonHasHistory(): bool
    {
        return $this->contest !== null
            && $this->contest->slates()->where('status', Slate::SETTLED)->exists();
    }

    #[Computed]
    public function members()
    {
        return $this->group->memberships()
            ->with('user:id,first_name,last_name,handle')
            ->orderByRaw("role = 'commissioner' desc")
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The link this group travels by — the primary invite, crediting the
     * sharer when they hold a handle. Never rendered for lobbies: rooms
     * are joined from the lobby, not by invitation.
     */
    #[Computed]
    public function joinUrl(): string
    {
        return route('pickem.join', array_filter([
            'code' => $this->group->code,
            'by' => auth()->user()?->handle,
        ]));
    }

    #[Computed]
    public function isCommissioner(): bool
    {
        return $this->seatOf(auth()->user())?->isCommissioner() ?? false;
    }

    #[Computed]
    public function isMember(): bool
    {
        return $this->seatOf(auth()->user()) !== null;
    }

    /**
     * The room's week — resolved by id, never off the model property:
     * Livewire re-hydrates `$group` without relations, so `$group->week`
     * in the template is a lazy-load 500 on the second request.
     */
    #[Computed]
    public function roomWeek(): ?\App\Models\Week
    {
        return $this->group->week_id === null ? null : \App\Models\Week::find($this->group->week_id);
    }

    /**
     * The tabs this room fields: a transient weekly room has no season to
     * stand on, so its plate is Slate | Members.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function tabs(): array
    {
        return $this->group->isRoom()
            ? ['slate' => 'Slate', 'members' => 'Members']
            : ['slate' => 'Slate', 'season' => 'Season', 'members' => 'Members'];
    }

    public function join(JoinGroup $action): void
    {
        try {
            $action->handle(auth()->user(), $this->group);
        } catch (PickemParticipationGated) {
            $this->addError('group', Voice::line('groups.verify_first'));

            return;
        } catch (ContestFull) {
            $this->addError('group', Voice::line('contest.room.full'));

            return;
        }

        session()->flash('status', Voice::line('groups.joined', ['group' => $this->group->name]));
        unset($this->members, $this->isMember, $this->isCommissioner);
    }

    public function leave(LeaveGroup $action)
    {
        try {
            $action->handle(auth()->user(), $this->group);
        } catch (GroupNeedsCommissioner) {
            $this->addError('group', Voice::line('groups.leave.commissioner'));

            return;
        }

        session()->flash('status', Voice::line('groups.left', ['group' => $this->group->name]));

        return $this->redirectRoute('pickem.home', navigate: true);
    }

    /** The modal's radio tap. Validation is presentation; the Action gates. */
    public function choosePivot(string $mode): void
    {
        $choice = ContestMode::tryFrom($mode);

        if ($choice !== null && $this->pivotChoices->contains($choice)) {
            $this->pivotTo = $choice->value;
        }
    }

    public function changeMode(ChangeGroupMode $action): void
    {
        $target = ContestMode::tryFrom($this->pivotTo ?? '');

        if ($target === null) {
            $this->addError('mode', Voice::line('mode.change.pick_one'));

            return;
        }

        try {
            $action->handle(auth()->user(), $this->group, $target);
        } catch (NotGroupCommissioner) {
            abort(403);
        } catch (ModeChangeBlocked $blocked) {
            $this->addError('mode', Voice::line($blocked->reason === ModeChangeBlocked::USED
                ? 'mode.change.blocked.used'
                : 'mode.change.blocked.inflight'));

            return;
        }

        Flux::modal('change-mode')->close();
        session()->flash('status', Voice::line('mode.change.done', ['mode' => $target->label()]));
        $this->pivotTo = null;

        unset(
            $this->contest, $this->pivotChoices, $this->slate, $this->surfaceStatus,
            $this->weekStandings, $this->seasonStandings, $this->seasonHasHistory,
        );
    }

    public function remove(int $userId, RemoveGroupMember $action): void
    {
        $member = User::findOrFail($userId);

        try {
            $action->handle(auth()->user(), $this->group, $member);
        } catch (NotGroupCommissioner) {
            abort(403);
        }

        session()->flash('status', Voice::line('groups.member.removed', ['name' => $member->first_name]));
        unset($this->members);
    }

    /** @return Collection<int, Slate> */
    protected function pickableSlates(): Collection
    {
        return collect([$this->slate])->filter(fn (?Slate $slate) => $slate?->isPublished());
    }

    /** A pick can create the week's entry, so the room state rides along. */
    protected function refreshPicks(): void
    {
        $this->refreshPickState();
        unset($this->slate, $this->surfaceStatus, $this->weekStandings, $this->seasonStandings);
    }

    private function normalizedView(string $view): string
    {
        if ($this->group->isRoom() && $view === 'season') {
            return 'slate';
        }

        return in_array($view, self::VIEWS, true) ? $view : 'slate';
    }

    private function seatOf(?User $user): ?GroupMember
    {
        return $user === null
            ? null
            : $this->group->memberships()->where('user_id', $user->id)->first();
    }
}; ?>

<div class="flex flex-col gap-5 lg:mx-auto lg:w-full lg:max-w-5xl">
    @php
        $heroMeta = $group->isRoom()
            ? collect([
                $this->contest?->mode->label(),
                $this->roomWeek ? \App\Support\Cadence::displayWeekLabel($this->roomWeek, $this->slate?->saturday) : null,
                $group->member_cap !== null
                    ? $this->members->count().' of '.$group->member_cap.' seats'
                    : $this->members->count().' '.Str::plural('member', $this->members->count()),
            ])->filter()->implode(' · ')
            : null;
    @endphp

    <x-group-hero :group="$group" :contest="$this->contest" :members-count="$this->members->count()" :meta="$heroMeta">
        <x-slot:actions>
            @if ($this->isMember && ! $group->isLobby())
                {{-- Copies the invite LINK without leaving the hero; the
                     link and the fallback code live on the Members tab. --}}
                <div
                    x-data="{
                        copied: false,
                        copy() {
                            navigator.clipboard.writeText(@js($this->joinUrl));
                            this.copied = true;
                            setTimeout(() => this.copied = false, 2000);
                        },
                    }"
                >
                    <button
                        type="button"
                        x-on:click="copy()"
                        class="rounded-lg bg-white/10 px-3 py-1.5 text-sm font-medium transition-colors hover:bg-white/20 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                    >
                        <span x-show="! copied">Invite</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </button>
                </div>
            @endif

            @if ($this->isCommissioner && $this->pivotChoices->isNotEmpty())
                <flux:modal.trigger name="change-mode">
                    <button
                        type="button"
                        class="rounded-lg bg-white/10 p-2 transition-colors hover:bg-white/20 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                        aria-label="Change the group's game"
                    >
                        <flux:icon name="cog-6-tooth" variant="mini" />
                    </button>
                </flux:modal.trigger>
            @endif
        </x-slot:actions>
    </x-group-hero>

    {{-- WHAT THIS ROOM IS. The lobby sells uniform rows now, so the
         pitch — the flavor's own one-line rules, or the mode's, plus its
         optional zinger — is said HERE, where somebody who tapped the row
         is deciding whether to sit down. This was the contest card's
         cargo and the card is gone; without this re-home the blurbs and
         zingers have no render site at all. --}}
    @if ($group->isRoom() && $this->contest !== null)
        @php
            $roomFlavor = $group->flavorEnum();
            $roomZinger = $roomFlavor === null
                ? ''
                : Voice::line($roomFlavor->zingerKey(), ['conference' => $roomFlavor->conferenceName() ?? '']);
        @endphp

        <div class="flex flex-col gap-1">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $roomFlavor?->blurb() ?? $this->contest->mode->blurb() }}</p>
            @if ($roomZinger !== '')
                <p class="text-micro italic text-zinc-400 dark:text-zinc-500">&ldquo;{{ $roomZinger }}&rdquo;</p>
            @endif
        </div>
    @endif

    {{-- The pivot's announcement, lingering a week so members who missed
         the note still walk in on the news rather than a changed room. --}}
    @if ($this->contest?->mode_changed_at?->gt(now()->subDays(7)))
        <flux:callout icon="megaphone">
            <flux:callout.heading>New mode: {{ $this->contest->mode->label() }}</flux:callout.heading>
            <flux:callout.text>{{ Voice::line('group.mode_changed', ['mode' => $this->contest->mode->label()]) }}</flux:callout.text>
        </flux:callout>
    @endif

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('status') }}
        </div>
    @endif

    @if ($this->notice)
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ $this->notice }}
        </div>
    @endif

    @error('group')
        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror

    {{-- Only a lobby is readable from outside, so this door is theirs. --}}
    @if (! $this->isMember)
        <div class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:subheading class="min-w-0">{{ Voice::line('groups.lobbies.subheading') }}</flux:subheading>
            <flux:button wire:click="join" variant="primary" class="shrink-0">Join this lobby</flux:button>
        </div>
    @endif

    <x-plate
        :tabs="$this->tabs"
        :selected="$view"
        model="view"
        key-prefix="group-tab"
    />

    @if ($view === 'slate')
        @if ($this->slate?->isPublished())
            {{-- A room's week has a winner, and the room says so out loud. --}}
            @if ($group->isRoom() && $this->surfaceStatus === 'final')
                @php
                    $winners = $this->weekStandings
                        ->filter(fn ($row) => $row['won'])
                        ->map(fn ($row) => $row['user']->handle !== null ? '@'.$row['user']->handle : $row['user']->name);
                @endphp

                @if ($winners->isNotEmpty())
                    <flux:callout icon="trophy">
                        <flux:callout.heading>{{ Voice::line('contest.room.winner', ['name' => $winners->implode(' & ')]) }}</flux:callout.heading>
                    </flux:callout>
                @endif
            @endif

            @if (in_array($this->surfaceStatus, ['live', 'prelim', 'final'], true) && $this->weekStandings->isNotEmpty())
                <x-standings-table
                    :rows="$this->weekStandings"
                    :status="$this->surfaceStatus"
                    :headings="['Pts']"
                    title="This week"
                />
            @endif

            @include('partials.pick-slate', ['slate' => $this->slate, 'interactive' => $this->isMember])
        @else
            {{-- Dashed border = "not yet", the house grammar for a promise. --}}
            <div class="flex flex-col gap-2 rounded-xl border border-dashed border-zinc-300 px-4 py-4 dark:border-zinc-700">
                @if ($this->isCommissioner && $this->contest !== null)
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('group.slate.build_prompt') }}</p>
                    <flux:button
                        :href="route('pickem.build', $group)"
                        wire:navigate
                        size="sm"
                        variant="primary"
                        class="self-start"
                    >
                        Build the slate
                    </flux:button>
                @else
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('group.slate.waiting') }}</p>
                @endif
            </div>
        @endif
    @elseif ($view === 'season')
        @if ($this->seasonHasHistory || $this->surfaceStatus !== null)
            <x-standings-table
                :rows="$this->seasonStandings"
                :headings="['Wins', 'Pts', 'This week']"
                title="Season"
                :status="$this->surfaceStatus === 'live' ? 'live' : null"
            />
        @else
            <p class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                {{ Voice::line('group.season.empty') }}
            </p>
        @endif
    @else
        @if ($this->isMember && ! $group->isLobby())
            <div
                class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                x-data="{
                    copiedLink: false,
                    copiedCode: false,
                    canShare: typeof navigator.share === 'function',
                    copyLink() {
                        navigator.clipboard.writeText(@js($this->joinUrl));
                        this.copiedLink = true;
                        setTimeout(() => this.copiedLink = false, 2000);
                    },
                    copyCode() {
                        navigator.clipboard.writeText(@js($group->code));
                        this.copiedCode = true;
                        setTimeout(() => this.copiedCode = false, 2000);
                    },
                    share() {
                        navigator.share({
                            title: @js($group->name),
                            text: @js(Voice::line('groups.invite.share_text', ['group' => $group->name])),
                            url: @js($this->joinUrl),
                        }).catch(() => {});
                    },
                }"
            >
                <flux:heading size="lg">Invite link</flux:heading>
                <flux:subheading>{{ Voice::line('groups.invite.hint', ['group' => $group->name]) }}</flux:subheading>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="max-w-full truncate font-mono text-sm font-semibold">{{ Str::after($this->joinUrl, '://') }}</span>
                    <flux:button x-on:click="copyLink()" size="sm" variant="primary">
                        <span x-show="! copiedLink">Copy link</span>
                        <span x-show="copiedLink" x-cloak>Copied</span>
                    </flux:button>
                    <flux:button x-show="canShare" x-cloak x-on:click="share()" size="sm">
                        <flux:icon.box-arrow-up variant="micro" />
                        Share
                    </flux:button>
                </div>

                {{-- The spoken-word fallback for a friend across the room. --}}
                <div class="flex items-center gap-3 border-t border-zinc-100 pt-2 dark:border-zinc-800/60">
                    <p class="text-micro text-zinc-400">Or read them the code</p>
                    <span class="font-mono text-lg font-bold tracking-widest">{{ $group->code }}</span>
                    <flux:button x-on:click="copyCode()" size="xs" variant="ghost">
                        <span x-show="! copiedCode">Copy</span>
                        <span x-show="copiedCode" x-cloak>Copied</span>
                    </flux:button>
                </div>
            </div>
        @endif

        <div class="flex flex-col gap-2">
            @foreach ($this->members as $seat)
                <div
                    wire:key="member-{{ $seat->id }}"
                    class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-2.5 dark:border-zinc-700"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ $seat->user->name }}
                            @if ($seat->user->handle)
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">&commat;{{ $seat->user->handle }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        @if ($seat->isCommissioner())
                            <flux:badge size="sm" color="amber">Commissioner</flux:badge>
                        @elseif ($this->isCommissioner)
                            <flux:button
                                wire:click="remove({{ $seat->user_id }})"
                                wire:confirm="Remove {{ $seat->user->first_name }} from the group?"
                                size="xs"
                                variant="ghost"
                            >
                                Remove
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if ($this->isMember)
            <flux:button
                wire:click="leave"
                wire:confirm="Leave {{ $group->name }}?"
                variant="ghost"
                class="self-start text-red-600 dark:text-red-400"
            >
                Leave group
            </flux:button>
        @endif
    @endif

    {{-- The room's talk, at the foot of the room and under every tab — it
         belongs to the GROUP, not to whichever tab you happen to be on. Not
         a fourth tab: x-plate holds three, and a slate's chatter following
         you from Slate to Members is the point rather than a side effect. --}}
    <div class="border-t border-zinc-200 pt-6 dark:border-zinc-800">
        <livewire:conversation :topic="$group" :key="'talk-group-'.$group->id" />
    </div>

    {{-- THE PIVOT: one deliberate act per season, consequences said
         plainly, and the announcement is a statement — never a checkbox.
         Three live modes made this a radiogroup: pick the new mode, then
         throw the one lever. --}}
    @if ($this->isCommissioner && $this->pivotChoices->isNotEmpty() && $this->contest !== null)
        <flux:modal name="change-mode" class="w-full max-w-md">
            <div class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">Change the game</flux:heading>
                    <flux:subheading>{{ Voice::line('mode.change.warning') }}</flux:subheading>
                </div>

                <div role="radiogroup" aria-label="New mode" class="flex flex-col gap-2">
                    @foreach ($this->pivotChoices as $choice)
                        <x-mode-card
                            wire:key="pivot-{{ $choice->value }}"
                            wire:click="choosePivot('{{ $choice->value }}')"
                            :mode="$choice"
                            :selected="$pivotTo === $choice->value"
                        />
                    @endforeach
                </div>

                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('mode.change.note') }}</p>

                @error('mode')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                <div class="flex items-center gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Keep {{ $this->contest->mode->label() }}</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="changeMode" variant="primary" :disabled="$pivotTo === null">
                        {{ $pivotTo !== null ? 'Switch to '.ContestMode::from($pivotTo)->label() : 'Switch' }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
