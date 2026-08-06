<?php

use App\Enums\Poll;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Scope;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Poll rankings, with the poll, season and week all switchable.
 *
 * Defaults come from CfbCalendar rather than from config or "latest season",
 * because a season exists in the database months before any poll is published
 * for it — selecting on year alone lands the user on an empty page.
 */
new class extends Component
{
    #[Url]
    public string $poll = '';

    #[Url]
    public ?int $year = null;

    /** A week id, not a number — releases span three season types. */
    #[Url]
    public ?int $release = null;

    /**
     * The plate's active tab. NOT `#[Url]`, deliberately: the poll fully
     * determines its division, and a second querystring parameter saying the
     * same thing is a second thing that can disagree with the first.
     */
    public string $division = Scope::FBS;

    public function mount(CfbCalendar $calendar): void
    {
        // A poll this screen does not carry — the AFCA small-college polls,
        // or a mistyped querystring — resolves exactly like no poll at all,
        // so the deep link lands on the default instead of an orphaned list.
        if ($this->poll !== '' && ! $this->carries($this->poll)) {
            $this->poll = '';
            $this->year = null;
            $this->release = null;
        }

        // CFP once it exists for the season, AP until then.
        $this->poll = $this->poll ?: $calendar->defaultPoll()->value;
        $this->year ??= $calendar->rankingsYear($this->poll);
        $this->release ??= $calendar->latestRankingRelease($this->year, $this->poll);
        $this->division = $this->divisionOf();
    }

    /**
     * Switching poll re-resolves the week, because polls do not all run for the
     * same weeks — the CFP rankings only start in November.
     */
    public function updatedPoll(CfbCalendar $calendar): void
    {
        // Reachable from the client with any string, not just the menu's
        // options — an uncarried poll falls back rather than rendering.
        if (! $this->carries($this->poll)) {
            $this->poll = $calendar->defaultPoll()->value;
        }

        $this->division = $this->divisionOf();
        $this->year = $calendar->rankingsYear($this->poll);
        $this->release = $calendar->latestRankingRelease($this->year, $this->poll);
    }

    /**
     * Switching division switches POLL: the tabs partition the polls the way
     * Standings' tabs partition conferences, so a division means its leading
     * published poll — majors first for FBS — and the year and release
     * re-resolve exactly as a poll change does, because the division's poll
     * may live in an earlier season than the one on screen.
     */
    public function updatedDivision(CfbCalendar $calendar): void
    {
        $poll = collect(Poll::inDivision($this->division))
            ->first(fn (Poll $p) => in_array($p->value, $this->publishedPolls, true));

        // Reachable from the client with any string; an unknown or empty
        // division falls back to the one the current poll belongs to.
        if ($poll === null) {
            $this->division = $this->divisionOf();

            return;
        }

        $this->poll = $poll->value;
        $this->year = $calendar->rankingsYear($this->poll);
        $this->release = $calendar->latestRankingRelease($this->year, $this->poll);
    }

    private function divisionOf(): string
    {
        return Poll::tryFrom($this->poll)?->division() ?? Scope::FBS;
    }

    /** Whether this screen offers the poll at all — the AFCA polls have no division. */
    private function carries(string $poll): bool
    {
        return Poll::tryFrom($poll)?->division() !== null;
    }

    public function updatedYear(CfbCalendar $calendar): void
    {
        $this->release = $calendar->latestRankingRelease($this->year, $this->poll);
    }

    /**
     * Pick a release from the strip.
     *
     * Named to match the scoreboard's handler, and taking the same second
     * argument, because both screens drive the SAME `x-week-scroller` and its
     * `wire:click` is baked in. The bracket is meaningless here — it exists so
     * the postseason can be shown as two pills over one week id — so it is
     * accepted and ignored rather than making the component configurable.
     */
    public function selectWeek(int $weekId, string $bracket = ''): void
    {
        $this->release = $weekId;
    }

    /**
     * The releases, shaped for the strip.
     *
     * No `range`: a poll is published on a day, not across a week, so the
     * scroller's second line is left off.
     *
     * @return list<array{week_id:int, label:string}>
     */
    #[Computed]
    public function releaseStrip(): array
    {
        return $this->releases;
    }

    /**
     * Whether this release has anything to have moved FROM.
     *
     * Not cosmetic, and measured: a preseason poll carries no `previous_rank`
     * on any row, and neither does `cfp-seedings` — so a fixed column set
     * prints twenty-five consecutive "NR"s, a column saying nothing
     * twenty-five times, on this screen's own default view all summer.
     *
     * Derived from the collection already fetched, so it costs no query.
     */
    #[Computed]
    public function showsMovement(): bool
    {
        return $this->rankings->contains(fn (Ranking $r) => $r->previous_rank !== null);
    }

    /**
     * Every poll with rows in ANY season — what decides which division tabs
     * render at all. Not year-scoped, deliberately: a tab exists so long as
     * its division has something to show, and switching to it re-resolves the
     * year to wherever that something lives.
     *
     * @return list<string>
     */
    #[Computed]
    public function publishedPolls(): array
    {
        return Cache::remember(
            'rankings:published-polls',
            3600,
            fn () => Ranking::query()->distinct()->pluck('poll')->all()
        );
    }

    /**
     * The plate's tabs: FBS and FCS, where a published poll exists. The AFCA
     * small-college polls carry no division, so they can never surface a tab.
     *
     * Derived from the data rather than hardcoded, so a fresh database does
     * not offer tabs whose screens can only ever be empty — the same rule as
     * a Top 25 filter with no poll behind it.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function divisionTabs(): array
    {
        $divisions = array_map(
            fn (string $poll) => Poll::tryFrom($poll)?->division(),
            $this->publishedPolls
        );

        return array_filter(
            [Scope::FBS => 'FBS', Scope::FCS => 'FCS'],
            fn (string $division) => in_array($division, $divisions, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Polls with rows for this season, narrowed to the active division —
     * the tabs partition the polls, so the menu holds only the current tab's.
     * Majors first within it.
     *
     * @return array<string,string>
     */
    #[Computed]
    public function polls(): array
    {
        return collect(app(CfbCalendar::class)->availablePolls($this->year))
            ->filter(fn (Poll $p) => $p->division() === $this->division)
            ->mapWithKeys(fn (Poll $p) => [$p->value => $p->label()])
            ->all();
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return Cache::remember(
            "rankings:years:{$this->poll}",
            3600,
            fn () => Season::query()
                ->whereIn('id', Ranking::where('poll', $this->poll)->distinct()->pluck('season_id'))
                ->orderByDesc('year')
                ->pluck('year')
                ->unique()
                ->values()
                ->all()
        );
    }

    /**
     * Poll releases for this season, newest first — spanning the preseason
     * poll, the weekly polls, and the final rankings.
     *
     * @return list<array{week_id:int, label:string}>
     */
    #[Computed]
    public function releases(): array
    {
        return app(CfbCalendar::class)->rankingReleases($this->year, $this->poll);
    }

    #[Computed]
    public function rankings()
    {
        // Spans season types: the preseason poll and the final rankings live
        // outside the regular season.
        $seasonIds = Season::where('year', $this->year)->pluck('id');

        if ($seasonIds->isEmpty()) {
            return collect();
        }

        return Ranking::query()
            // `location` is not optional here: the table renders placeName(),
            // and leaving the column out of a constrained eager load makes
            // every team silently fall back to its display name — which reads
            // as a design decision rather than a missing column.
            ->with('team:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark')
            ->whereIn('season_id', $seasonIds)
            ->where('poll', $this->poll)
            ->when($this->release, fn ($q) => $q->where('week_id', $this->release))
            ->orderBy('rank')
            ->get();
    }
}; ?>

<div class="flex flex-col gap-4">
    <h1 class="sr-only">Rankings</h1>

    {{--
        The same plate Standings wears — division tabs on the rule, the WHO
        and WHEN menus on the shelf. The one difference in meaning: these tabs
        partition POLLS rather than conferences, so the menu beside them holds
        only the active division's polls and switching tab jumps to that
        division's leading published poll.
    --}}
    @php
        $pollItems = collect($this->polls)
            ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    @endphp
    <x-plate
        :tabs="$this->divisionTabs"
        :selected="$division"
        model="division"
        key-prefix="rankdivision"
    >
        <x-slot:actions>
            <x-filter-menu
                :items="$pollItems"
                :selected="$poll"
                model="poll"
                label="Poll"
                key-prefix="poll"
                align="end"
                class="shrink-0"
            />

            <x-season-menu :years="$this->years" :selected="$year" class="shrink-0" />
        </x-slot:actions>
    </x-plate>

    {{--
        The release picker as the plate's second row, in the same strip Scores
        uses — the season's polls visible by swiping rather than hidden behind
        a select. Period WITHIN a season is always a scroller, never a menu.

        Deliberately the SAME component, so the active pill is filled exactly as
        it is on Scores. Two horizontal strips in one app should not speak two
        visual languages for the same idea.
    --}}
    <x-week-scroller :weeks="$this->releaseStrip" :selected="$release" class="-mt-1" />

    @if ($this->rankings->isNotEmpty())
        {{--
            A table, not twenty-five cards. This is the one League screen whose
            content is purely tabular, and it borrows Standings' markup verbatim
            so the two read as one system rather than two takes on a list.

            Scrolls inside its own container, so the page body never scrolls
            sideways on a phone — and because the columns below are conditional,
            the two narrow cases (a preseason poll, a CFP release) fit 390px
            without needing to.
        --}}
        <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
            {{-- `whitespace-nowrap` for the same reason as Standings: a record
                 column sized to its header is narrower than "13-0", and a
                 wrapped record makes one row taller than its neighbours. The
                 team cell overrides it with `truncate`. --}}
            <table class="w-full text-stat whitespace-nowrap">
                <thead>
                    <tr class="border-b border-zinc-200 text-micro uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                        <th scope="col" class="px-3 py-2 text-right font-medium">
                            <span aria-hidden="true">#</span>
                            <span class="sr-only">Rank</span>
                        </th>
                        <th scope="col" class="px-2 py-2 text-left font-medium">Team</th>
                        <th scope="col" class="px-2 py-2 text-right font-medium">
                            <span aria-hidden="true">Rec</span>
                            <span class="sr-only">Record</span>
                        </th>

                        @if ($this->showsMovement)
                            <th scope="col" class="px-3 py-2 text-right font-medium">
                                <span aria-hidden="true">Mov</span>
                                <span class="sr-only">Movement since the last poll</span>
                            </th>
                        @endif
                    </tr>
                </thead>

                <tbody>
                    @foreach ($this->rankings as $entry)
                        @php $movement = $entry->previous_rank ? $entry->previous_rank - $entry->rank : 0; @endphp

                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60"
                            wire:key="rank-{{ $entry->id }}">
                            <td class="tabular px-3 py-2 text-right font-bold">{{ $entry->rank }}</td>

                            {{--
                                The only link in the row, as on Standings.

                                `w-full max-w-0` is what makes the name truncate
                                instead of the table scrolling. A cell sizes to
                                its content's min-content width, and `truncate`
                                sets `nowrap`, so the min-content of a team name
                                is the WHOLE string — the same trap `min-w-0`
                                solves for flex items. Zeroing the max width lets
                                the cell be told its size rather than asking for
                                one, and `w-full` hands it whatever the numeric
                                columns leave.
                            --}}
                            <td class="w-full max-w-0 px-2 py-2">
                                <div class="flex min-w-0 items-center gap-2">
                                    {{-- The place, not the mascot: "Ohio
                                         State", never "Ohio State Buckeyes". A
                                         ranked list is scanned rather than
                                         read, and the mascot is decoration
                                         sitting in front of the word the reader
                                         is looking for — the same call the game
                                         card already makes. --}}
                                    <x-team-link :team="$entry->team" label="location" class="min-w-0 flex-1" />

                                    @if ($entry->first_place_votes > 0)
                                        {{--
                                            The votes ride WITH the team rather
                                            than in a column of their own. Only
                                            a handful of teams in any poll have
                                            any, so a dedicated column was
                                            mostly empty and spent width the
                                            team name wanted.

                                            The count alone — the word "first"
                                            was saying what the blue chip beside
                                            the top three teams already says.
                                            Its meaning is carried for screen
                                            readers by the `sr-only` text, since
                                            there is no longer a column header
                                            to do it.

                                            A plain span rather than
                                            `flux:badge`: that component's own
                                            base classes include `inline-flex`,
                                            which is exactly what silently
                                            defeated the `hidden sm:inline-flex`
                                            this replaces.
                                        --}}
                                        <span
                                            title="{{ $entry->first_place_votes }} first-place votes"
                                            class="tabular inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-micro font-semibold text-blue-700 ring-1 ring-blue-200 ring-inset bg-blue-50 dark:bg-blue-950/40 dark:text-blue-300 dark:ring-blue-900"
                                        >{{ $entry->first_place_votes }}<span class="sr-only"> first-place votes</span></span>
                                    @endif
                                </div>
                            </td>

                            <td class="tabular px-2 py-2 text-right text-zinc-500">{{ $entry->record }}</td>

                            @if ($this->showsMovement)
                                <td class="px-3 py-2 text-right text-micro font-medium">
                                    @if ($movement > 0)
                                        <span class="text-emerald-600 dark:text-emerald-400">▲{{ $movement }}</span>
                                    @elseif ($movement < 0)
                                        <span class="text-red-600 dark:text-red-400">▼{{ abs($movement) }}</span>
                                    @elseif ($entry->previous_rank === null)
                                        <span class="text-zinc-400">NR</span>
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <flux:callout icon="trophy">
            <flux:callout.heading>No poll published</flux:callout.heading>
            <flux:callout.text>Nothing for this poll, season and week.</flux:callout.text>
        </flux:callout>
    @endif
</div>
