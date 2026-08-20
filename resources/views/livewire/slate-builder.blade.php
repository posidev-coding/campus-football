<?php

use App\Actions\AddSlateGame;
use App\Actions\PublishSlate;
use App\Actions\RemoveSlateGame;
use App\Actions\SetSlateGameLine;
use App\Actions\SetSlateGameTier;
use App\Actions\SetTiebreaker;
use App\Enums\ContestMode;
use App\Enums\TiebreakerMetric;
use App\Exceptions\PickemParticipationGated;
use App\Livewire\Concerns\MakesPicks;
use App\Models\Contest;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Services\Contests\ContestLine;
use App\Services\Contests\GameQualityScore;
use App\Services\Contests\SuggestSlate;
use App\Support\Cadence;
use App\Support\Voice;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * THE COMMISSIONER'S WIZARD — the weekly set-the-slate ritual as guided
 * steps: Games → (Tiers) → Lines → Tiebreaker → Preview. Pre-filled from
 * suggestions, every slot swappable, published through the one door that
 * freezes the lines.
 *
 * Lines shown here are LIVE current odds — display only, refreshed each
 * render. Nothing is frozen until PublishSlate copies the numbers onto
 * the rows; a game whose line has not posted wears "Line pending" and
 * publish refuses the slate while it is still on it.
 *
 * The steppers move a WHOLE POINT per tap: the seed is already on the
 * half-point grid, so ±1.0 keeps every stop legal — ±0.5 would land on
 * whole numbers the action rightly throws at. The band guard here mirrors
 * the disabled buttons so a hand-rolled call is a quiet no-op, never a
 * 500; the ACTION stays the law.
 *
 * The preview step renders the SAME pick partial the clubhouse embeds,
 * read-only — what the commissioner approves is literally what the group
 * gets, because it is the same file.
 */
new class extends Component
{
    use MakesPicks;

    private const TEAM_COLUMNS = 'id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark,color,alt_color,header_style';

    public Group $group;

    public Contest $contest;

    public int $slateId;

    /** The Saturday this board is being built for, as a plain date string. */
    public string $saturday = '';

    #[Url(except: 'games')]
    public string $step = 'games';

    /** @var list<string> */
    public array $problems = [];

    public function mount(Group $group): void
    {
        $this->group = $group;

        $runsIt = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', auth()->id())
            ->where('role', GroupMember::COMMISSIONER)
            ->exists();

        abort_unless($runsIt, 403);

        // One mode per group per season: the contest is the group's, so
        // the URL never has to carry it.
        $contest = $group->contests()
            ->where('season_year', app(CfbCalendar::class)->currentYear())
            ->first();

        abort_if($contest === null, 404);
        $this->contest = $contest;

        $weekId = app(CfbCalendar::class)->defaultWeekId($contest->season_year);

        abort_if($weekId === null, 404);

        $week = Week::query()->findOrFail($weekId);

        /*
         * The board is keyed to a SATURDAY, not to ESPN's week — one week
         * can hold two of them. `currentSaturday()` is the one this pick'em
         * week is playing (Tuesday turnover); the week's primary card is the
         * fallback when the clock sits outside this week entirely.
         */
        $current = Cadence::currentSaturday();

        $saturday = collect(Cadence::saturdaysIn($week))
            ->first(fn ($day) => $day->toDateString() === $current->toDateString())
            ?? Cadence::saturdayOf($week);

        abort_if($saturday === null, 404);

        $this->saturday = $saturday->toDateString();

        $slate = Slate::query()->firstOrCreate(
            ['contest_id' => $contest->id, 'saturday' => $this->saturday],
            ['week_id' => $week->id, 'status' => Slate::DRAFT],
        );

        $this->slateId = $slate->id;

        if ($slate->status === Slate::DRAFT && $slate->games()->count() === 0) {
            $this->fillFromSuggestions($slate);
        }

        $this->step = $this->normalizedStep($this->step);
    }

    /** #[Url] hydrates without firing the hook, hence mount() normalizes too. */
    public function updatedStep(string $value): void
    {
        $this->step = $this->normalizedStep($value);
    }

    /**
     * The ritual's stations, by mode — Classic has no tiers to set.
     *
     * @return array<string, string> step key => label
     */
    #[Computed]
    public function steps(): array
    {
        $steps = ['games' => 'Games'];

        if ($this->tiered) {
            $steps['tiers'] = 'Tiers';
        }

        return [...$steps, 'lines' => 'Lines', 'tiebreaker' => 'Tiebreaker', 'preview' => 'Preview'];
    }

    public function next(): void
    {
        $keys = array_keys($this->steps);
        $at = array_search($this->step, $keys, true);

        $this->step = $keys[min($at + 1, count($keys) - 1)];
    }

    public function back(): void
    {
        $keys = array_keys($this->steps);
        $at = array_search($this->step, $keys, true);

        $this->step = $keys[max($at - 1, 0)];
    }

    #[Computed]
    public function slate(): Slate
    {
        $team = self::TEAM_COLUMNS;

        // The full games graph rides along for the preview step — the
        // partial is the clubhouse's and reads what the clubhouse loads.
        return Slate::query()
            ->with([
                'week',
                'contest.group:id,name,kind',
                "games.game.homeTeam:{$team}",
                "games.game.awayTeam:{$team}",
                "tiebreakerGame.game.homeTeam:{$team}",
                "tiebreakerGame.game.awayTeam:{$team}",
                'tiebreakerTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
            ])
            ->findOrFail($this->slateId);
    }

    #[Computed]
    public function board()
    {
        $team = self::TEAM_COLUMNS;

        return $this->slate->games()
            ->with([
                "game.homeTeam:{$team}",
                "game.awayTeam:{$team}",
                'game.odds',
                'favorite:id,slug,location,display_name,short_display_name,abbreviation',
            ])
            ->get()
            ->sortBy([['tier', 'asc'], ['position', 'asc']])
            ->values();
    }

    #[Computed]
    public function candidates()
    {
        $team = self::TEAM_COLUMNS;

        return Game::query()
            ->slateEligible()
            ->where('week_id', $this->slate->week_id)
            ->upcoming()
            ->whereNotIn('id', $this->slate->games()->pluck('game_id'))
            ->with(["homeTeam:{$team}", "awayTeam:{$team}", 'odds'])
            ->orderBy('kickoff_at')
            ->get()
            ->filter(fn (Game $game) => $game->inSlateWindow())
            // ONE BOARD, ONE SATURDAY — the split-week rule. AddSlateGame
            // holds the same line as the gate; this keeps the list honest.
            ->filter(fn (Game $game) => $game->kickoff_at->timezone(config('cfb.timezone'))->toDateString()
                === $this->slate->saturday?->toDateString());
    }

    /** Whether this contest's mode tiers at all — the engine's answer, so
     * the Woodshed gets its Tiers station without this file naming modes. */
    #[Computed]
    public function tiered(): bool
    {
        return $this->contest->mode->engine($this->contest->settings)->tierSpec() !== null;
    }

    /** @return array<int, int> tier => count on the board */
    #[Computed]
    public function tierCounts(): array
    {
        return $this->board->whereNotNull('tier')->countBy('tier')->all();
    }

    #[Computed]
    public function deadline(): ?\Carbon\CarbonImmutable
    {
        return $this->slate->week === null ? null : Cadence::slateDeadline($this->slate->week);
    }

    /**
     * The legal burden range for a row, from the book's current number —
     * what the steppers disable against. Null when the book has nothing.
     *
     * @return array{0: float, 1: float}|null
     */
    public function burdenBand(SlateGame $slateGame): ?array
    {
        $market = GameQualityScore::usableCurrentOdd($slateGame->game);

        return $market === null ? null : ContestLine::band((float) $market->spread);
    }

    /** The live book line, phrased the way the books phrase it ("UGA -6.5"). */
    public function lineFor(Game $game): ?string
    {
        $current = GameQualityScore::usableCurrentOdd($game);

        if ($current === null) {
            return null;
        }

        if ($current->details !== null) {
            return $current->details;
        }

        // Compose from the game's own loaded teams — the odd's favorite
        // relation is deliberately not eager-loaded for a fallback path.
        $favorite = $current->favorite_team_id === $game->home_team_id
            ? $game->homeTeam
            : $game->awayTeam;

        return ($favorite?->placeName() ?? 'Favorite').' -'.abs($current->spread);
    }

    public function add(int $gameId): void
    {
        app(AddSlateGame::class)->handle(auth()->user(), $this->slate, Game::query()->findOrFail($gameId));
        $this->fresh();
    }

    public function remove(int $slateGameId): void
    {
        app(RemoveSlateGame::class)->handle(auth()->user(), $this->slate, SlateGame::query()->findOrFail($slateGameId));
        $this->fresh();
    }

    public function setTier(int $slateGameId, int $tier): void
    {
        app(SetSlateGameTier::class)->handle(auth()->user(), $this->slate, SlateGame::query()->findOrFail($slateGameId), $tier);
        $this->fresh();
    }

    /**
     * Nudge a row's contest line by WHOLE points, or seed a row whose book
     * posted late ($steps = 0 from the Use-line button). The band guard
     * mirrors the disabled buttons; the action remains the law.
     */
    public function nudge(int $slateGameId, int $steps, SetSlateGameLine $action): void
    {
        $slateGame = SlateGame::query()->findOrFail($slateGameId);
        $slateGame->loadMissing('game.odds');

        $market = GameQualityScore::usableCurrentOdd($slateGame->game);

        if ($market === null) {
            return;
        }

        $burden = $slateGame->spread === null
            ? ContestLine::defaultBurden((float) $market->spread)
            : abs($slateGame->spread) + $steps * 1.0;

        [$floor, $ceiling] = ContestLine::band((float) $market->spread);

        if ($burden < $floor || $burden > $ceiling) {
            return;
        }

        $action->handle(auth()->user(), $this->slate, $slateGame, $burden);
        $this->fresh();
    }

    /** "Back to the book": re-seed from the market's current number. */
    public function resetLine(int $slateGameId, SetSlateGameLine $action): void
    {
        $slateGame = SlateGame::query()->findOrFail($slateGameId);
        $slateGame->loadMissing('game.odds');

        $market = GameQualityScore::usableCurrentOdd($slateGame->game);

        if ($market === null) {
            return;
        }

        $action->handle(auth()->user(), $this->slate, $slateGame, ContestLine::defaultBurden((float) $market->spread));
        $this->fresh();
    }

    public function tiebreak(int $slateGameId): void
    {
        app(SetTiebreaker::class)->handle(auth()->user(), $this->slate, SlateGame::query()->findOrFail($slateGameId));
        $this->fresh();
    }

    /**
     * Change the QUESTION the tiebreaker asks. One-sided metrics default
     * to the home team until a chip says otherwise — the action refuses a
     * team that is not in the game.
     */
    public function setTiebreakerMetric(string $metric, ?int $teamId, SetTiebreaker $action): void
    {
        $tiebreakerGame = SlateGame::query()->with('game')
            ->findOrFail($this->slate->tiebreaker_slate_game_id);

        $chosen = TiebreakerMetric::from($metric);

        $action->handle(
            auth()->user(),
            $this->slate,
            $tiebreakerGame,
            $chosen,
            $teamId ?? ($chosen->needsTeam() ? $tiebreakerGame->game->home_team_id : null),
        );

        $this->fresh();
    }

    public function suggest(): void
    {
        $slate = $this->slate;

        if ($slate->status === Slate::DRAFT) {
            $slate->games()->delete();
            $slate->update(['tiebreaker_slate_game_id' => null]);
            $this->fillFromSuggestions($slate);
        }

        $this->fresh();
    }

    public function publish(PublishSlate $action)
    {
        try {
            $this->problems = $action->handle(auth()->user(), $this->slate);
        } catch (PickemParticipationGated) {
            $this->problems = ['groups.verify_first'];

            return;
        }

        if ($this->problems !== []) {
            $this->fresh();

            return;
        }

        session()->flash('status', Voice::line('wizard.published'));

        return $this->redirectRoute('pickem.group', $this->group, navigate: true);
    }

    /** @return Collection<int, Slate> the preview partial's feed */
    protected function pickableSlates(): Collection
    {
        return collect([$this->slate]);
    }

    private function normalizedStep(string $step): string
    {
        return array_key_exists($step, $this->steps) ? $step : 'games';
    }

    private function fillFromSuggestions(Slate $slate): void
    {
        // The slate's own Saturday, so a split ESPN week cannot suggest a
        // board spanning two of them.
        $suggested = app(SuggestSlate::class)->for($this->contest, $slate->week, $slate->saturday);

        foreach ($suggested as $i => $row) {
            $seed = array_diff_key($row, array_flip(['game_id', 'score']));

            SlateGame::query()->firstOrCreate(
                ['slate_id' => $slate->id, 'game_id' => $row['game_id']],
                ['position' => $i + 1, ...$seed],
            );
        }
    }

    private function fresh(): void
    {
        unset($this->slate, $this->board, $this->candidates, $this->tierCounts, $this->myPicks, $this->myEntries);
    }
}; ?>

<div class="flex flex-col gap-5 lg:mx-auto lg:w-full lg:max-w-3xl">
    <h1 class="sr-only">Build the slate</h1>

    @if ($this->slate->isPublished())
        {{-- The week is out the door; the clubhouse owns it now. --}}
        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center gap-2">
                <flux:heading size="lg">{{ $contest->mode->label() }} slate</flux:heading>
                <flux:badge size="sm" color="green">Published</flux:badge>
            </div>
            <flux:subheading>{{ Voice::line('wizard.already_published') }}</flux:subheading>
            <flux:button :href="route('pickem.group', $group)" wire:navigate variant="primary" class="self-start">
                Open the clubhouse
            </flux:button>
        </div>
    @else
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2">
                <flux:heading size="xl" class="truncate">{{ $contest->mode->label() }} slate</flux:heading>
                <flux:badge size="sm" color="zinc" class="shrink-0">Draft</flux:badge>
            </div>

            <x-signup-progress
                :step="array_search($step, array_keys($this->steps), true) + 1"
                :total="count($this->steps)"
            />
        </div>

        {{-- The league clock, said once, up top. --}}
        @if ($this->deadline)
            <flux:callout icon="clock">
                <flux:callout.text>
                    {{ Voice::line('wizard.deadline', ['due' => $this->deadline->format('D g:ia')]) }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-semibold">
                Step {{ array_search($step, array_keys($this->steps), true) + 1 }} of {{ count($this->steps) }}
                · {{ $this->steps[$step] }}
            </p>

            @if ($step === 'games')
                <span class="tabular shrink-0 text-sm font-medium {{ $this->board->count() === $contest->mode->engine($contest->settings)->slateSize() ? 'text-emerald-700 dark:text-emerald-400' : 'text-zinc-500' }}">
                    {{ $this->board->count() }} of {{ $contest->mode->engine($contest->settings)->slateSize() }}
                </span>
            @elseif ($step === 'tiers')
                <span class="flex shrink-0 items-center gap-2 text-micro font-medium text-zinc-500">
                    @foreach ($contest->mode->engine($contest->settings)->tierSpec() ?? [] as $tier => $need)
                        <span wire:key="tier-count-{{ $tier }}" @class(['tabular', 'text-emerald-700 dark:text-emerald-400' => ($this->tierCounts[$tier] ?? 0) === $need])>
                            T{{ $tier }}: {{ $this->tierCounts[$tier] ?? 0 }}/{{ $need }}
                        </span>
                    @endforeach
                </span>
            @endif
        </div>

        {{-- ============================== STEP 1 · GAMES ============= --}}
        @if ($step === 'games')
            <div wire:key="step-games" class="flex flex-col gap-3">
                <div class="flex items-center justify-between gap-3">
                    <p class="min-w-0 text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('wizard.games.hint') }}</p>
                    <flux:button wire:click="suggest" size="sm" variant="ghost" class="shrink-0">Fill from suggestions</flux:button>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($this->board as $slateGame)
                        @php $game = $slateGame->game; @endphp
                        <div
                            wire:key="board-{{ $slateGame->id }}"
                            class="flex min-w-0 flex-col rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <div class="flex items-center justify-between gap-2 border-b border-zinc-100 px-3 py-1.5 text-micro dark:border-zinc-800/60">
                                <span class="shrink-0 font-medium text-zinc-600 dark:text-zinc-400">
                                    {{ $game->kickoff_at?->setTimezone(config('cfb.timezone'))->format('D g:ia') ?? 'TBD' }}
                                </span>
                                <button
                                    type="button"
                                    wire:click="remove({{ $slateGame->id }})"
                                    class="-my-0.5 rounded px-1.5 py-0.5 font-semibold text-zinc-400 transition-colors hover:text-red-600 dark:hover:text-red-400"
                                >Remove</button>
                            </div>

                            <div class="flex flex-col gap-1.5 px-3 py-2.5">
                                @foreach ([['team' => $game->awayTeam, 'record' => $game->away_record], ['team' => $game->homeTeam, 'record' => $game->home_record]] as $side)
                                    @php $ranks = App\Support\GameRanks::forGame($game); @endphp
                                    <x-team-link
                                        :team="$side['team']"
                                        :rank="$loop->first ? $ranks['away'] : $ranks['home']"
                                        :record="$side['record']"
                                        :link="false"
                                        label="location"
                                    />
                                @endforeach
                            </div>

                            <div class="px-3 pb-2.5">
                                @if ($this->lineFor($game) !== null)
                                    <x-odds-strip :game="$game" />
                                @else
                                    <p class="rounded-md bg-amber-50 px-2.5 py-1.5 text-micro font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-400">Line pending</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($this->board->isEmpty())
                    <p class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        {{ Voice::line('wizard.games.empty') }}
                    </p>
                @endif

                <flux:heading size="lg">Available games</flux:heading>

                @forelse ($this->candidates as $game)
                    <div
                        wire:key="candidate-{{ $game->id }}"
                        class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-2.5 dark:border-zinc-700"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ $game->awayTeam?->placeName() ?? 'TBD' }} at {{ $game->homeTeam?->placeName() ?? 'TBD' }}
                            </p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $game->kickoff_at->timezone(config('cfb.timezone'))->format('D g:i A') }}
                                @if ($line = $this->lineFor($game))
                                    · {{ $line }}
                                @else
                                    · <span class="text-amber-600 dark:text-amber-400">Line pending</span>
                                @endif
                            </p>
                        </div>
                        <flux:button
                            wire:click="add({{ $game->id }})"
                            size="xs"
                            class="shrink-0"
                            :disabled="$this->lineFor($game) === null"
                        >
                            Add
                        </flux:button>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        {{ Voice::line('wizard.no_candidates') }}
                    </p>
                @endforelse
            </div>
        @endif

        {{-- ============================== STEP · TIERS =============== --}}
        @if ($step === 'tiers')
            @php
                $tierEngine = $contest->mode->engine($contest->settings);
                $tierSpec = $tierEngine->tierSpec() ?? [];
                $tierValues = collect(array_keys($tierSpec))->map(
                    fn (int $tier) => 'Tier '.$tier.' pays '.$tierEngine->pointsFor((new App\Models\SlateGame)->forceFill(['tier' => $tier]))
                );
            @endphp

            <div wire:key="step-tiers" class="flex flex-col gap-3">
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('wizard.tiers.hint') }}</p>

                {{-- The stakes, from the engine — 9/7/4 or the founders' 8/6/4. --}}
                <p class="text-micro font-medium text-zinc-500">{{ $tierValues->implode(' · ') }}</p>

                <div class="flex flex-col gap-2">
                    @foreach ($this->board as $slateGame)
                        @php $game = $slateGame->game; @endphp
                        <div
                            wire:key="tier-row-{{ $slateGame->id }}"
                            class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-2.5 dark:border-zinc-700"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ $game->awayTeam?->placeName() ?? 'TBD' }} at {{ $game->homeTeam?->placeName() ?? 'TBD' }}
                                </p>
                                <p class="text-micro text-zinc-500 dark:text-zinc-400">
                                    {{ $this->lineFor($game) ?? 'Line pending' }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                @foreach (array_keys($tierSpec) as $tier)
                                    <button
                                        type="button"
                                        wire:click="setTier({{ $slateGame->id }}, {{ $tier }})"
                                        wire:key="tier-{{ $slateGame->id }}-{{ $tier }}"
                                        @class([
                                            'rounded-full px-2.5 py-0.5 text-xs font-semibold transition-colors',
                                            'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' => $slateGame->tier === $tier,
                                            'border border-zinc-300 text-zinc-500 hover:border-zinc-400 dark:border-zinc-600 dark:text-zinc-400' => $slateGame->tier !== $tier,
                                        ])
                                    >
                                        {{ $tier }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ============================== STEP · LINES =============== --}}
        @if ($step === 'lines')
            <div wire:key="step-lines" class="flex flex-col gap-3">
                {{-- The law, said plainly where the lever is. --}}
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('wizard.lines.hint') }}</p>

                <div class="flex flex-col gap-2">
                    @foreach ($this->board as $slateGame)
                        @php
                            $game = $slateGame->game;
                            $band = $this->burdenBand($slateGame);
                            $burden = $slateGame->spread === null ? null : abs($slateGame->spread);
                        @endphp
                        <div
                            wire:key="line-row-{{ $slateGame->id }}"
                            class="flex items-center gap-3 rounded-xl border border-zinc-200 px-4 py-2.5 dark:border-zinc-700"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium">
                                    {{ $game->awayTeam?->placeName() ?? 'TBD' }} at {{ $game->homeTeam?->placeName() ?? 'TBD' }}
                                </p>
                                {{-- The book's number stays small — the
                                     audit trail, not the product. --}}
                                <p class="text-micro text-zinc-500 dark:text-zinc-400">
                                    Book: {{ $this->lineFor($game) ?? 'Line pending' }}
                                </p>
                            </div>

                            @if ($burden !== null)
                                {{-- YOUR line, large: the number the whole
                                     group grades against. --}}
                                <p class="tabular shrink-0 text-lg font-bold tracking-tight">
                                    {{ $slateGame->favorite?->abbreviation ?? $slateGame->favorite?->placeName() }} -{{ $burden }}
                                </p>

                                <div class="flex shrink-0 items-center gap-1">
                                    <flux:button
                                        wire:click="nudge({{ $slateGame->id }}, -1)"
                                        size="xs"
                                        variant="ghost"
                                        aria-label="Move the line down one point"
                                        :disabled="$band === null || $burden - 1.0 < $band[0]"
                                    >−1</flux:button>
                                    <flux:button
                                        wire:click="nudge({{ $slateGame->id }}, 1)"
                                        size="xs"
                                        variant="ghost"
                                        aria-label="Move the line up one point"
                                        :disabled="$band === null || $burden + 1.0 > $band[1]"
                                    >+1</flux:button>
                                    <flux:button
                                        wire:click="resetLine({{ $slateGame->id }})"
                                        size="xs"
                                        variant="ghost"
                                        aria-label="Back to the book's number"
                                    >Book</flux:button>
                                </div>
                            @elseif ($this->lineFor($game) !== null)
                                <flux:button wire:click="nudge({{ $slateGame->id }}, 0)" size="xs" class="shrink-0">
                                    Use line
                                </flux:button>
                            @else
                                <span class="shrink-0 text-micro font-medium text-amber-600 dark:text-amber-400">Line pending</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- =========================== STEP · TIEBREAKER ============= --}}
        @if ($step === 'tiebreaker')
            <div wire:key="step-tiebreaker" class="flex flex-col gap-3">
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('wizard.tiebreaker.hint') }}</p>

                <div class="flex flex-col gap-2">
                    @foreach ($this->board as $slateGame)
                        @php
                            $game = $slateGame->game;
                            $chosen = $this->slate->tiebreaker_slate_game_id === $slateGame->id;
                        @endphp
                        <div
                            wire:key="tb-row-{{ $slateGame->id }}"
                            @class([
                                'flex flex-col gap-2 rounded-xl border px-4 py-2.5',
                                'border-blue-600 bg-blue-50/50 dark:border-blue-400 dark:bg-blue-950/30' => $chosen,
                                'border-zinc-200 dark:border-zinc-700' => ! $chosen,
                            ])
                        >
                            <button
                                type="button"
                                wire:click="tiebreak({{ $slateGame->id }})"
                                class="flex w-full items-center justify-between gap-3 text-start"
                            >
                                <span class="min-w-0 truncate font-medium">
                                    {{ $game->awayTeam?->placeName() ?? 'TBD' }} at {{ $game->homeTeam?->placeName() ?? 'TBD' }}
                                </span>
                                @if ($chosen)
                                    <flux:icon.check-circle-fill variant="mini" class="shrink-0 text-blue-600 dark:text-blue-400" />
                                @endif
                            </button>

                            @if ($chosen)
                                <div class="flex flex-wrap items-center gap-1">
                                    @foreach (TiebreakerMetric::cases() as $metric)
                                        <button
                                            type="button"
                                            wire:key="tb-metric-{{ $metric->value }}"
                                            wire:click="setTiebreakerMetric('{{ $metric->value }}', null)"
                                            @class([
                                                'rounded-full px-2.5 py-0.5 text-xs font-semibold transition-colors',
                                                'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' => $this->slate->tiebreaker_metric === $metric,
                                                'border border-zinc-300 text-zinc-500 hover:border-zinc-400 dark:border-zinc-600 dark:text-zinc-400' => $this->slate->tiebreaker_metric !== $metric,
                                            ])
                                        >
                                            {{ $metric->label() }}
                                        </button>
                                    @endforeach

                                    @if ($this->slate->tiebreaker_metric?->needsTeam())
                                        <span class="text-xs text-zinc-400">for</span>
                                        @foreach ([$game->awayTeam, $game->homeTeam] as $team)
                                            <button
                                                type="button"
                                                wire:key="tb-team-{{ $team?->id }}"
                                                wire:click="setTiebreakerMetric('{{ $this->slate->tiebreaker_metric->value }}', {{ $team?->id }})"
                                                @class([
                                                    'rounded-full px-2.5 py-0.5 text-xs font-semibold transition-colors',
                                                    'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' => $this->slate->tiebreaker_team_id === $team?->id,
                                                    'border border-zinc-300 text-zinc-500 hover:border-zinc-400 dark:border-zinc-600 dark:text-zinc-400' => $this->slate->tiebreaker_team_id !== $team?->id,
                                                ])
                                            >
                                                {{ $team?->placeName() ?? 'TBD' }}
                                            </button>
                                        @endforeach
                                    @endif

                                    @if ($this->slate->tiebreaker_metric !== null)
                                        <p class="w-full pt-1 text-micro text-zinc-500 dark:text-zinc-400">
                                            "{{ $this->slate->tiebreaker_metric->question($this->slate->tiebreakerGame, $this->slate->tiebreakerTeam) }}"
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ============================ STEP · PREVIEW =============== --}}
        @if ($step === 'preview')
            <div wire:key="step-preview" class="flex flex-col gap-3">
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('wizard.preview.hint') }}</p>

                @if ($this->problems !== [])
                    <div class="flex flex-col gap-1 rounded-xl border border-red-200 bg-red-50 px-4 py-3 dark:border-red-900 dark:bg-red-950">
                        @foreach ($this->problems as $key)
                            <p wire:key="problem-{{ $key }}" class="text-sm text-red-700 dark:text-red-300">
                                {{ Voice::line($key, ['size' => $contest->mode->engine($contest->settings)->slateSize()]) }}
                            </p>
                        @endforeach
                    </div>
                @endif

                {{-- The SAME partial the clubhouse embeds — what you
                     approve is what they get, because it is one file. --}}
                @include('partials.pick-slate', ['slate' => $this->slate, 'interactive' => false])

                <flux:button wire:click="publish" variant="primary" class="self-start">Publish the slate</flux:button>
            </div>
        @endif

        {{-- The ritual's feet. --}}
        <div class="flex items-center justify-between gap-3 border-t border-zinc-200 pt-3 dark:border-zinc-800">
            @if ($step !== array_key_first($this->steps))
                <flux:button wire:click="back" variant="ghost">Back</flux:button>
            @else
                <span></span>
            @endif

            @if ($step !== array_key_last($this->steps))
                <flux:button wire:click="next" variant="primary">Next</flux:button>
            @endif
        </div>
    @endif
</div>
