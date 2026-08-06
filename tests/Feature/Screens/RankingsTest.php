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

    /*
     * `location` pinned alongside the display name. The factory generates a
     * random city for it, so overriding the display name alone leaves a team
     * whose two names disagree — and every table here renders placeName().
     */
    $this->indiana = Team::factory()->create([
        'id' => 84, 'slug' => 'indiana-hoosiers',
        'location' => 'Indiana', 'display_name' => 'Indiana Hoosiers',
    ]);
    $this->miami = Team::factory()->create([
        'id' => 2390, 'slug' => 'miami-hurricanes',
        'location' => 'Miami', 'display_name' => 'Miami Hurricanes',
    ]);

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
        ->assertSee('Indiana');
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
        ->assertSee('Miami');
});

it('lets a user pick an earlier release', function () {
    Ranking::create([
        'season_id' => $this->season->id, 'week_id' => $this->week15->id,
        'poll' => 'ap', 'team_id' => $this->miami->id, 'rank' => 1, 'record' => '12-3',
    ]);

    Livewire::test('rankings')
        ->set('release', $this->week15->id)
        ->assertSee('Miami')
        ->assertDontSee('Indiana');
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

    it('names the place, never the mascot', function () {
        /*
         * "Indiana", not "Indiana Hoosiers". A ranked list is scanned rather
         * than read, and the mascot is decoration in front of the word the
         * reader is looking for — the same call the game card already makes.
         * It is also what buys the room to fit the table on a phone without
         * wrapping or scrolling.
         */
        $html = Livewire::test('rankings')->html();

        expect($html)
            ->toContain('>Indiana</span>')
            ->not->toContain('Hoosiers')
            ->not->toContain('Hurricanes');
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
            ->assertSee('Miami')
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

describe('the division plate', function () {
    it('offers a division tab only where a poll has rows', function () {
        /*
         * The fixture holds AP and Coaches only, so the plate is a single FBS
         * tab — derived from the data rather than hardcoded, the same rule as
         * a Top 25 filter with no poll behind it: a tab whose screen can only
         * ever be empty must not render.
         */
        Livewire::test('rankings')
            ->assertSet('division', 'fbs')
            ->assertSee('FBS')
            ->assertDontSee('FCS')
            ->assertDontSee('DII/DIII');
    });

    it('partitions the polls and switches division through the tabs', function () {
        Ranking::create([
            'season_id' => $this->season->id, 'week_id' => $this->week16->id,
            'poll' => 'fcs', 'team_id' => $this->miami->id, 'rank' => 1, 'record' => '13-3',
        ]);

        Livewire::test('rankings')
            // The FBS menu must not offer the other division's poll.
            ->assertSee('AP Top 25')
            ->assertDontSee('FCS Coaches')
            ->set('division', 'fcs')
            // A division means its leading published poll, week re-resolved.
            ->assertSet('poll', 'fcs')
            ->assertSet('release', $this->week16->id)
            ->assertSee('FCS Coaches')
            ->assertSee('Miami')
            ->assertDontSee('AP Top 25');
    });

    it('re-resolves the year when the division\'s poll lives in an earlier season', function () {
        /*
         * The FCS poll's newest rows may sit a season behind the FBS poll on
         * screen. Keeping the stale year would open the tab on an empty page
         * with the real rankings one season away — the same conflation of
         * "this poll's year" and "the year on screen" mount() already avoids.
         */
        $season2024 = Season::factory()->create([
            'year' => 2024, 'type' => Season::REGULAR,
            'start_date' => '2024-08-24', 'end_date' => '2024-12-14',
        ]);

        $week = Week::create([
            'season_id' => $season2024->id, 'number' => 16, 'name' => 'Week 16',
            'start_date' => '2024-12-08', 'end_date' => '2024-12-15',
        ]);

        Ranking::create([
            'season_id' => $season2024->id, 'week_id' => $week->id,
            'poll' => 'fcs', 'team_id' => $this->miami->id, 'rank' => 1, 'record' => '12-2',
        ]);

        Livewire::test('rankings')
            ->assertSet('year', 2025)
            ->set('division', 'fcs')
            ->assertSet('poll', 'fcs')
            ->assertSet('year', 2024)
            ->assertSet('release', $week->id)
            ->assertSee('Miami');
    });

    it('excludes the small-college polls entirely', function () {
        /*
         * This is a Division I app. The AFCA DII and DIII polls still sync
         * and store, but the screen never offers them — no tab, no menu
         * entry, and a deep link carrying one resolves like no poll at all
         * instead of rendering an orphaned list under the FBS tab.
         */
        Ranking::create([
            'season_id' => $this->season->id, 'week_id' => $this->week16->id,
            'poll' => 'afca-dii', 'team_id' => $this->miami->id, 'rank' => 1, 'record' => '11-0',
        ]);

        Livewire::test('rankings')
            ->assertDontSee('DII/DIII')
            ->assertDontSee('AFCA Div II');

        Livewire::withQueryParams(['poll' => 'afca-dii'])
            ->test('rankings')
            ->assertSet('poll', 'ap')
            ->assertSee('Indiana');

        // The client can push one straight at the property, menu or not.
        Livewire::test('rankings')
            ->set('poll', 'afca-dii')
            ->assertSet('poll', 'ap')
            ->assertSee('Indiana');
    });

    it('falls back rather than erroring on an unknown division', function () {
        // The plate writes $division from the client, so any string can
        // arrive. An unknown one snaps back to the current poll's division.
        Livewire::test('rankings')
            ->set('division', 'nonsense')
            ->assertSet('division', 'fbs')
            ->assertSet('poll', 'ap')
            ->assertSee('Indiana');
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
            ->assertSee('Miami')
            ->assertDontSee('Indiana');
    });
});
