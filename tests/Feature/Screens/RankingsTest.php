<?php

use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);

    // An upcoming season with no poll — the case that emptied the rail.
    Season::factory()->create([
        'year' => 2026, 'type' => Season::REGULAR,
        'start_date' => '2026-08-22', 'end_date' => '2026-12-13',
    ]);

    $this->week15 = Week::create(['season_id' => $this->season->id, 'number' => 15, 'name' => 'Week 15', 'start_date' => '2025-12-01', 'end_date' => '2025-12-07']);
    $this->week16 = Week::create(['season_id' => $this->season->id, 'number' => 16, 'name' => 'Week 16', 'start_date' => '2025-12-08', 'end_date' => '2025-12-14']);

    $this->indiana = Team::factory()->create(['id' => 84, 'slug' => 'indiana-hoosiers', 'display_name' => 'Indiana Hoosiers']);
    $this->miami = Team::factory()->create(['id' => 2390, 'slug' => 'miami-hurricanes', 'display_name' => 'Miami Hurricanes']);

    foreach ([['ap', 1, 66], ['coaches', 1, 62]] as [$poll, $rank, $votes]) {
        Ranking::create([
            'season_id' => $this->season->id, 'week_id' => $this->week16->id,
            'poll' => $poll, 'team_id' => $this->indiana->id, 'rank' => $rank,
            'previous_rank' => 1, 'points' => 1650, 'first_place_votes' => $votes, 'record' => '16-0',
        ]);
    }

    Ranking::create([
        'season_id' => $this->season->id, 'week_id' => $this->week16->id,
        'poll' => 'ap', 'team_id' => $this->miami->id, 'rank' => 2,
        'previous_rank' => 10, 'points' => 1584, 'record' => '13-3',
    ]);
});

it('renders rankings for guests', function () {
    $this->get(route('rankings'))->assertOk();
});

it('defaults to the latest season and release that actually have a poll', function () {
    // 2026 exists and is chronologically later, but has no poll at all.
    Livewire::test('rankings')
        ->assertSet('year', 2025)
        ->assertSet('release', $this->week16->id)
        ->assertSee('Indiana Hoosiers');
});

it('switches poll and re-resolves the release', function () {
    /*
     * Polls do not all run the same weeks — the CFP poll only starts in
     * week 11 — so changing poll has to re-resolve, not keep a stale release.
     *
     * Asserted on the chip's full accessible name rather than on the bare
     * count: "62" alone collides with points and ranks elsewhere on the page,
     * and matching a prefix of the title would pass whatever the chip renders.
     */
    Livewire::test('rankings')
        ->set('poll', 'coaches')
        ->assertSet('release', $this->week16->id)
        ->assertSee('62 first-place votes')
        ->assertDontSee('66 first-place votes');
});

it('defaults to AP before the CFP committee publishes', function () {
    Livewire::test('rankings')->assertSet('poll', 'ap');
});

it('defaults to CFP once a CFP poll exists for the season', function () {
    Ranking::create([
        'season_id' => $this->season->id, 'week_id' => $this->week16->id,
        'poll' => 'cfp', 'team_id' => $this->miami->id, 'rank' => 1, 'record' => '13-3',
    ]);

    Livewire::test('rankings')
        ->assertSet('poll', 'cfp')
        ->assertSee('Miami Hurricanes');
});

it('lets a user pick an earlier release', function () {
    Ranking::create([
        'season_id' => $this->season->id, 'week_id' => $this->week15->id,
        'poll' => 'ap', 'team_id' => $this->miami->id, 'rank' => 1, 'record' => '12-3',
    ]);

    Livewire::test('rankings')
        ->set('release', $this->week15->id)
        ->assertSee('Miami Hurricanes')
        ->assertDontSee('Indiana Hoosiers');
});

it('shows movement relative to the previous poll', function () {
    // Miami went 10 -> 2.
    Livewire::test('rankings')->assertSee('▲8');
});

it('links every ranked team', function () {
    Livewire::test('rankings')
        ->assertSee(route('team', $this->indiana), escape: false)
        ->assertSee(route('team', $this->miami), escape: false);
});

it('only offers polls that have data', function () {
    Livewire::test('rankings')
        ->assertSee('AP Top 25')
        ->assertSee('Coaches Poll')
        // No CFP rows exist for this season, so it must not be offered.
        ->assertDontSee('CFP Rankings');
});

it('shows an empty state rather than erroring for a season with no poll', function () {
    Livewire::test('rankings')
        ->set('year', 2026)
        ->assertOk()
        ->assertSee('No poll published');
});

describe('the table', function () {
    it('renders a real table rather than a column of cards', function () {
        // This is the one League screen whose content is purely tabular, and it
        // borrows Standings' markup so the two read as one system.
        Livewire::test('rankings')
            ->assertSee('<table', escape: false)
            ->assertSee('scope="col"', escape: false);
    });

    it('reaches every column at every width', function () {
        /*
         * The record and the vote count carried `hidden sm:block`, so a phone
         * could not reach them AT ALL — 390px read "1 | Indiana Hoosiers | NR".
         * Every breakpoint above base must be ADDITIVE; nothing may live only
         * above `sm`.
         */
        $html = Livewire::test('rankings')->html();

        expect($html)
            ->toContain('stat-grid')
            ->toContain('16-0')
            ->not->toContain('hidden sm:block')
            ->not->toContain('hidden sm:inline-flex');
    });

    it('shows a first-place count without spelling out "first"', function () {
        /*
         * The blue chip beside the top few teams already says what it is; the
         * word was doing no work. Its meaning survives for screen readers in
         * `sr-only` text, because there is no column header to carry it now
         * that the votes ride in the team cell.
         */
        $html = Livewire::test('rankings')->html();

        expect($html)
            ->toContain('>66<span class="sr-only"> first-place votes</span>')
            // Never the visible phrasing this replaces.
            ->not->toContain('66 first<')
            ->not->toContain('>66 first');
    });

    it('drops the points column entirely', function () {
        // Cut deliberately: points are the poll's arithmetic, not what a reader
        // came for, and the column cost width the team name wanted at 390px.
        expect(Livewire::test('rankings')->html())
            ->not->toContain('1,650')
            ->not->toContain('>Points<');
    });
});

describe('the movement column appears only when it can mean something', function () {
    it('carries no vote chips for a CFP release, which has none', function () {
        /*
         * Measured across every stored row: `cfp` (750) and `cfp-seedings` (24)
         * carry ZERO first-place votes. Chips living in the team cell means
         * that costs nothing — there is no column left standing empty through
         * the playoff race, which is when this screen is read most.
         */
        Ranking::create([
            'season_id' => $this->season->id, 'week_id' => $this->week16->id,
            'poll' => 'cfp', 'team_id' => $this->miami->id, 'rank' => 1,
            'previous_rank' => 3, 'record' => '13-3',
        ]);

        Livewire::test('rankings')
            ->assertSet('poll', 'cfp')
            ->assertSee('Miami Hurricanes')
            ->assertDontSee('first-place votes')
            // Movement survives, because this release has a previous rank.
            ->assertSee('Movement since the last poll');
    });

    it('drops movement for a poll with nothing to move from', function () {
        /*
         * A preseason poll has no `previous_rank` on any row, so the column was
         * twenty-five consecutive "NR"s — a column saying nothing, twenty-five
         * times, on the screen's default view all summer.
         */
        $preseason = Season::factory()->create([
            'year' => 2025, 'type' => Season::PRESEASON,
            'start_date' => '2025-02-01', 'end_date' => '2025-08-23',
        ]);

        $preWeek = Week::create([
            'season_id' => $preseason->id, 'number' => 1, 'name' => 'Week 1',
            'start_date' => '2025-08-01', 'end_date' => '2025-08-22',
        ]);

        Ranking::create([
            'season_id' => $preseason->id, 'week_id' => $preWeek->id,
            'poll' => 'ap', 'team_id' => $this->indiana->id, 'rank' => 1,
            'previous_rank' => null, 'points' => 1500, 'first_place_votes' => 40, 'record' => '0-0',
        ]);

        Livewire::test('rankings')
            ->call('selectWeek', $preWeek->id)
            ->assertDontSee('Movement since the last poll')
            // The rest of the row is intact — this drops a column, not data.
            ->assertSee('40 first-place votes')
            ->assertSee('0-0');
    });
});

describe('the release strip', function () {
    it('offers a pill per release instead of a third dropdown', function () {
        // A release only exists where the poll has rows, so week 15 has to be
        // given one before it can appear as a pill.
        Ranking::create([
            'season_id' => $this->season->id, 'week_id' => $this->week15->id,
            'poll' => 'ap', 'team_id' => $this->miami->id, 'rank' => 1, 'record' => '12-3',
        ]);

        Livewire::test('rankings')
            ->assertSee('Week 15')
            ->assertSee('Week 16')
            // The scroller marks the current release for its auto-centering.
            ->assertSee('data-active="true"', escape: false);
    });

    it('switches release through the scroller\'s own handler', function () {
        /*
         * `x-week-scroller` bakes in `wire:click="selectWeek(id, bracket)"`, so
         * this screen has to answer to that name and to that second argument —
         * the bracket is meaningless for a poll, and is accepted and ignored
         * rather than making a shared component configurable.
         */
        Ranking::create([
            'season_id' => $this->season->id, 'week_id' => $this->week15->id,
            'poll' => 'ap', 'team_id' => $this->miami->id, 'rank' => 1, 'record' => '12-3',
        ]);

        Livewire::test('rankings')
            ->call('selectWeek', $this->week15->id, '')
            ->assertSet('release', $this->week15->id)
            ->assertSee('Miami Hurricanes')
            ->assertDontSee('Indiana Hoosiers');
    });
});
