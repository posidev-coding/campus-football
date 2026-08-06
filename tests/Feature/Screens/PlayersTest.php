<?php

use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Position;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    // The screen's defaults come from cached lookups, and a cache surviving
    // between tests would pin every one of them to the first fixture built.
    Cache::flush();

    Season::factory()->create([
        'year' => 2026, 'type' => Season::REGULAR,
        'start_date' => '2026-08-22', 'end_date' => '2026-12-13',
    ]);

    Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC']);
    Conference::factory()->create(['id' => 5, 'name' => 'Big Ten Conference', 'short_name' => 'Big Ten']);

    foreach ([8, 5] as $conference) {
        ConferenceSeason::create([
            'conference_id' => $conference, 'season_year' => 2026, 'classification' => 'FBS',
        ]);
    }

    $this->georgia = Team::factory()->create([
        'id' => 61, 'slug' => 'georgia-bulldogs',
        'location' => 'Georgia', 'display_name' => 'Georgia Bulldogs',
    ]);

    $this->ohio = Team::factory()->create([
        'id' => 194, 'slug' => 'ohio-state-buckeyes',
        'location' => 'Ohio State', 'display_name' => 'Ohio State Buckeyes',
    ]);

    TeamSeason::create(['team_id' => 61, 'season_year' => 2026, 'conference_id' => 8, 'classification' => 'FBS']);
    TeamSeason::create(['team_id' => 194, 'season_year' => 2026, 'conference_id' => 5, 'classification' => 'FBS']);

    Position::create(['id' => 8, 'name' => 'Quarterback', 'abbreviation' => 'QB']);
    Position::create(['id' => 1, 'name' => 'Wide Receiver', 'abbreviation' => 'WR']);

    $this->qb = Athlete::create([
        'id' => 4685578, 'slug' => 'gunner-stockton', 'display_name' => 'Gunner Stockton',
        'last_name' => 'Stockton', 'birth_city' => 'Tiger', 'birth_state' => 'GA',
    ]);

    $this->wr = Athlete::create([
        'id' => 4432577, 'slug' => 'jeremiah-smith', 'display_name' => 'Jeremiah Smith',
        'last_name' => 'Smith', 'birth_city' => 'Miami Gardens', 'birth_state' => 'FL',
    ]);

    AthleteTeamSeason::create([
        'athlete_id' => $this->qb->id, 'team_id' => 61, 'season_year' => 2026,
        'jersey' => '14', 'position_id' => 8, 'position_group' => 'offense',
        'experience_class' => 'Senior',
    ]);

    AthleteTeamSeason::create([
        'athlete_id' => $this->wr->id, 'team_id' => 194, 'season_year' => 2026,
        'jersey' => '4', 'position_id' => 1, 'position_group' => 'offense',
        'experience_class' => 'Junior',
    ]);
});

it('renders for guests', function () {
    $this->get(route('players'))->assertOk();
});

it('shows the newest season that has a roster, and offers no season picker', function () {
    /*
     * Not resultsYear(), which points at the last season with GAMES and is a
     * year behind all summer. And no selector at all: ESPN publishes only the
     * current roster, so an earlier season is a name list with no positions —
     * a player's history belongs on their own page.
     */
    $screen = Livewire::test('players')
        ->assertSee('Gunner Stockton')
        ->assertSee('Jeremiah Smith');

    expect($screen->get('year'))->toBe(2026)
        ->and($screen->html())->not->toContain('aria-label="Season"');
});

describe('the name filter', function () {
    it('matches a prefix of either the display name or the last name', function () {
        /*
         * Prefix, matching Search::players() and the model's own
         * #[SearchUsingPrefix]. A screen that matched differently from the
         * search bar above it would read as a bug.
         */
        Livewire::test('players')
            ->set('q', 'Smith')
            ->assertSee('Jeremiah Smith')
            ->assertDontSee('Gunner Stockton');

        Livewire::test('players')
            ->set('q', 'Gunner')
            ->assertSee('Gunner Stockton')
            ->assertDontSee('Jeremiah Smith');
    });

    it('does not match mid-word, which is the cost of riding the index', function () {
        // Stated rather than worked around: 34,836 athletes make a
        // contains-LIKE a full scan on every keystroke.
        Livewire::test('players')
            ->set('q', 'mith')
            ->assertSee('No players');
    });

    it('treats a LIKE wildcard as a literal character', function () {
        // Typed `%` should match a literal `%`, not every player alive.
        Livewire::test('players')
            ->set('q', '%')
            ->assertSee('No players');
    });
});

describe('the scope filter', function () {
    it('narrows to one conference', function () {
        Livewire::test('players')
            ->set('scope', '8')
            ->assertSee('Gunner Stockton')
            ->assertDontSee('Jeremiah Smith');
    });

    it('shows nothing for a conference with no teams, rather than everything', function () {
        /*
         * Scope::teamIds() returns NULL for "do not filter" and [] for "filter
         * to nothing". Conflating them shows the whole league to a reader who
         * asked for a conference that has no members this season.
         */
        Conference::factory()->create(['id' => 99, 'name' => 'Empty Conference', 'short_name' => 'EC']);

        Livewire::test('players')
            ->set('scope', '99')
            ->assertSee('No players')
            ->assertDontSee('Gunner Stockton');
    });
});

describe('the position filter', function () {
    it('is a menu, led by All positions, that filters on pick', function () {
        // A menu rather than a pill strip: 22 positions overflow a 390px
        // track, and nothing scrolls sideways except the week scroller. Each
        // row carries the descriptive plural beside the abbreviation.
        Livewire::test('players')
            ->assertSee('Filter by position')
            ->assertSeeInOrder(['All positions', 'QB · Quarterbacks'])
            ->call('selectPosition', 'QB')
            ->assertSet('position', 'QB')
            ->assertSee('Gunner Stockton')
            ->assertDontSee('Jeremiah Smith');
    });

    it('clears itself when the active position is picked again', function () {
        // A filter with no way back to "everything" is a trap: the All item
        // is the way out, and re-picking the active one is the other.
        Livewire::test('players')
            ->call('selectPosition', 'QB')
            ->call('selectPosition', 'QB')
            ->assertSet('position', '')
            ->assertSee('Jeremiah Smith');
    });

    it('orders positions by squad, offense before defense before special teams', function () {
        /*
         * Only about six pills are visible at 390px, and alphabetical buried QB
         * seventeenth behind C, CB, DB, DE... Ordered by ESPN's own
         * `position_group` and then by squad size, which is how every roster
         * page is laid out — including this app's own team page.
         */
        Position::create(['id' => 22, 'name' => 'Place Kicker', 'abbreviation' => 'PK']);
        Position::create(['id' => 30, 'name' => 'Linebacker', 'abbreviation' => 'LB']);

        foreach ([[22, 'special_teams', 800], [30, 'defense', 810]] as [$pid, $group, $id]) {
            Athlete::create(['id' => $id, 'display_name' => "Player {$id}", 'last_name' => 'Zed']);

            AthleteTeamSeason::create([
                'athlete_id' => $id, 'team_id' => 61, 'season_year' => 2026,
                'position_id' => $pid, 'position_group' => $group,
            ]);
        }

        $positions = Livewire::test('players')->get('positions');

        expect(array_search('QB', $positions, true))
            ->toBeLessThan(array_search('LB', $positions, true))
            ->and(array_search('LB', $positions, true))
            ->toBeLessThan(array_search('PK', $positions, true));
    });

    it('returns every id sharing an abbreviation', function () {
        /*
         * ESPN's position ids duplicate: `LS` resolves to TWO of them (39 with
         * 256 players and 78 with 13). Keyed on the id, the menu renders "LS"
         * twice and each entry silently hides the other's players.
         */
        Position::create(['id' => 39, 'name' => 'Long Snapper', 'abbreviation' => 'LS']);
        Position::create(['id' => 78, 'name' => 'Long Snapper', 'abbreviation' => 'LS']);

        foreach ([[39, 'First Snapper', 900], [78, 'Second Snapper', 901]] as [$position, $name, $id]) {
            Athlete::create(['id' => $id, 'display_name' => $name, 'last_name' => 'Snapper']);

            AthleteTeamSeason::create([
                'athlete_id' => $id, 'team_id' => 61, 'season_year' => 2026,
                'position_id' => $position, 'position_group' => 'special_teams',
            ]);
        }

        $screen = Livewire::test('players')->call('selectPosition', 'LS');

        $screen->assertSee('First Snapper')->assertSee('Second Snapper');

        // And one menu entry, not one per id.
        expect(substr_count($screen->html(), 'wire:key="pos-LS"'))->toBe(1)
            ->and($screen->get('positions'))->toBe(['QB', 'WR', 'LS']);
    });

    it('disappears entirely when the newest roster carries no positions', function () {
        /*
         * The gate is COVERAGE, not presence: a handful of positioned rows
         * would build a strip that looks complete and filters to 3% of the
         * roster. Exercised by making the NEWEST season a box-score-derived one
         * — a jersey and a team, no position — which is the only way the screen
         * can reach that state now that there is no season picker.
         */
        TeamSeason::create(['team_id' => 61, 'season_year' => 2027, 'conference_id' => 8, 'classification' => 'FBS']);

        // One positioned row among many unpositioned ones: 1 in 11 is well
        // under the floor, so the strip must not render.
        Athlete::create(['id' => 700, 'display_name' => 'Old Player', 'last_name' => 'Player']);
        AthleteTeamSeason::create([
            'athlete_id' => 700, 'team_id' => 61, 'season_year' => 2027, 'position_id' => 8,
        ]);

        foreach (range(701, 710) as $id) {
            Athlete::create(['id' => $id, 'display_name' => "Player {$id}", 'last_name' => 'Player']);

            AthleteTeamSeason::create([
                'athlete_id' => $id, 'team_id' => 61, 'season_year' => 2027,
            ]);
        }

        Livewire::test('players')
            ->assertSee('Old Player')
            ->assertDontSee('Filter by position');
    });
});

it('costs the same number of queries however many rows are on the page', function () {
    /*
     * The row prints the player's team and position. Left to resolve those
     * itself it would lazy-load per row — and lazy loading is disabled
     * app-wide, so fifty rows would be a hard 500, not a slow page. The screen
     * passes the roster row it already eager loaded.
     *
     * Asserted as a COUNT rather than by catching the exception, because the
     * regression that matters is a query that scales with the page.
     */
    $count = function () {
        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::test('players')->html();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    // Warm first. The roster year, the position strip and the scope filter's
    // conference list are all cached, so a cold render costs three times a warm
    // one — measuring that instead of the row count proves nothing.
    $count();

    $twoRows = $count();

    foreach (range(1000, 1040) as $id) {
        Athlete::create(['id' => $id, 'display_name' => "Zed Player {$id}", 'last_name' => 'Zulu']);

        AthleteTeamSeason::create([
            'athlete_id' => $id, 'team_id' => 61, 'season_year' => 2026, 'position_id' => 8,
        ]);
    }

    expect($count())->toBe($twoRows);
});

describe('infinite scroll', function () {
    beforeEach(function () {
        // 63 players in all: the two from the outer fixture plus 61 Zulus.
        foreach (range(1000, 1060) as $id) {
            Athlete::create([
                'id' => $id, 'display_name' => "Zed Player {$id}",
                'first_name' => 'Zed', 'last_name' => 'Zulu',
            ]);

            AthleteTeamSeason::create([
                'athlete_id' => $id, 'team_id' => 61, 'season_year' => 2026, 'position_id' => 8,
            ]);
        }
    });

    it('renders one chunk, then grows a chunk at a time', function () {
        /*
         * A chunk, not the whole division: 13,580 rows rendered at once is a
         * page nobody can use and a response nobody wants to download.
         */
        $screen = Livewire::test('players');

        expect($screen->get('players')->count())->toBe(50);
        $screen->assertSee('Load more');

        $screen->call('loadMore');

        expect($screen->get('players')->count())->toBe(63);
    });

    it('keeps the rows already loaded rather than swapping in the next page', function () {
        // This is what makes it infinite scroll rather than pagination: the
        // first chunk has to still be there after the second arrives.
        Livewire::test('players')
            ->assertSee('Jeremiah Smith')
            ->call('loadMore')
            ->assertSee('Jeremiah Smith')
            ->assertSee('Zed Player 1060');
    });

    it('retires the sentinel once the list is exhausted', function () {
        // A "Load more" on a list with nothing left is a button that does
        // nothing, which reads as broken rather than as finished.
        Livewire::test('players')
            ->call('loadMore')
            ->assertSet('hasMore', false)
            ->assertDontSee('Load more');
    });

    it('cannot be pushed past the end', function () {
        // wire:intersect can fire more than once, and the guard is what stops a
        // run of them growing the limit without bound.
        $screen = Livewire::test('players')
            ->call('loadMore')->call('loadMore')->call('loadMore');

        expect($screen->get('perPage'))->toBe(100)
            ->and($screen->get('players')->count())->toBe(63);
    });

    it('collapses back to one chunk when a filter changes', function () {
        /*
         * Otherwise a reader who narrows to one conference keeps scrolling
         * through however many rows they had already loaded, in a list they
         * just made shorter.
         */
        Livewire::test('players')
            ->call('loadMore')
            ->set('q', 'Gunner')
            ->assertSet('perPage', 50)
            ->assertSee('Gunner Stockton')
            ->assertDontSee('Zed Player 1060');
    });

    it('offers no page numbers — the sentinel is the whole control', function () {
        expect(Livewire::test('players')->html())
            ->not->toContain('aria-label="Pagination')
            ->not->toContain('Go to page');
    });
});

describe('the search placeholder', function () {
    it('names the selected position, in the plural', function () {
        /*
         * The pill strip scrolls, so the active pill is often off-screen by the
         * time a reader is looking at the rows. Saying it in the placeholder
         * costs no vertical space, which a heading repeating a pill did.
         */
        $screen = Livewire::test('players');

        expect($screen->get('searchPlaceholder'))->toBe('Search players…');

        expect($screen->call('selectPosition', 'QB')->get('searchPlaceholder'))
            ->toBe('Search Quarterbacks…');
    });

    it('picks the row that actually names the position', function () {
        /*
         * ESPN's ids duplicate, and the junk twin carries the abbreviation as
         * its own name: `WR` is both id 1 ("Wide Receiver") and id 24 ("WR").
         * A plain first() returns "WR" about half the time, which would read
         * "Search WRS…".
         */
        Position::create(['id' => 24, 'name' => 'WR', 'abbreviation' => 'WR']);

        expect(Livewire::test('players')->call('selectPosition', 'WR')->get('searchPlaceholder'))
            ->toBe('Search Wide Receivers…');
    });

    it('leaves an acronym alone rather than pluralizing it', function () {
        // "EDGE" is the position's whole name; "EDGES" is not a word anyone
        // uses for it.
        Position::create(['id' => 264, 'name' => 'EDGE', 'abbreviation' => 'EDGE']);

        Athlete::create(['id' => 810, 'display_name' => 'Edge Rusher', 'last_name' => 'Rusher']);
        AthleteTeamSeason::create([
            'athlete_id' => 810, 'team_id' => 61, 'season_year' => 2026,
            'position_id' => 264, 'position_group' => 'defense',
        ]);

        expect(Livewire::test('players')->call('selectPosition', 'EDGE')->get('searchPlaceholder'))
            ->toBe('Search EDGE…');
    });

    it('handles the irregular plural football actually has', function () {
        expect((new Position(['name' => 'Offensive Lineman']))->pluralName())
            ->toBe('Offensive Linemen');
    });

    it('reaches the rendered input, not just the property', function () {
        Livewire::test('players')
            ->call('selectPosition', 'QB')
            ->assertSee('Search Quarterbacks…');
    });

    it('falls back to the abbreviation for a position it cannot name', function () {
        // The filter keys on the abbreviation, so a value with no matching row
        // still has to produce a placeholder rather than "Search …".
        expect(Livewire::test('players')->call('selectPosition', 'ZZ')->get('searchPlaceholder'))
            ->toBe('Search ZZ…');
    });
});

describe('sorting', function () {
    beforeEach(function () {
        // Surnames and first names deliberately in opposite alphabetical order,
        // so a sort that silently fell back to display_name would look right
        // under one option and wrong under the other.
        $this->qb->update(['first_name' => 'Gunner', 'last_name' => 'Stockton']);
        $this->wr->update(['first_name' => 'Jeremiah', 'last_name' => 'Smith']);
    });

    it('sorts by last name unless told otherwise', function () {
        /*
         * The default, and the one that rides an index: it is how a roster, a
         * box score and a depth chart are all listed, and it agrees with what
         * the name filter searches.
         */
        Livewire::test('players')
            ->assertSet('sort', 'last')
            // Smith before Stockton.
            ->assertSeeInOrder(['Jeremiah Smith', 'Gunner Stockton']);
    });

    it('sorts by name: first, then last, ascending', function () {
        // Gunner before Jeremiah — the opposite order to the default, which is
        // what proves it reads first_name rather than falling through.
        Livewire::test('players')
            ->set('sort', 'name')
            ->assertSeeInOrder(['Gunner Stockton', 'Jeremiah Smith']);
    });

    it('reverses surname order, and the tiebreak with it', function () {
        /*
         * Last is the only option carrying a direction — "teams, Z first"
         * answers no question — so the reverse lives in its own value rather
         * than in a second control that would mean nothing for the others.
         *
         * The tiebreak must reverse too. A list flipped at the top and
         * ascending underneath is not reversed, it is two sorts: the two
         * Stocktons have to come back in the opposite order as well.
         */
        foreach ([['Aaron', 601], ['Zane', 602]] as [$first, $id]) {
            Athlete::create([
                'id' => $id, 'display_name' => "{$first} Stockton",
                'first_name' => $first, 'last_name' => 'Stockton',
            ]);

            AthleteTeamSeason::create([
                'athlete_id' => $id, 'team_id' => 61, 'season_year' => 2026, 'position_id' => 8,
            ]);
        }

        Livewire::test('players')
            ->set('sort', 'last')
            ->assertSeeInOrder(['Jeremiah Smith', 'Aaron Stockton', 'Gunner Stockton', 'Zane Stockton']);

        Livewire::test('players')
            ->set('sort', 'last_desc')
            ->assertSeeInOrder(['Zane Stockton', 'Gunner Stockton', 'Aaron Stockton', 'Jeremiah Smith']);
    });

    it('offers the four options in the menu, and names the current one', function () {
        // The button is icon-only, so its accessible name is the only place the
        // active sort is spoken.
        Livewire::test('players')
            ->assertSeeInOrder(['Name', 'Last (A–Z)', 'Last (Z–A)', 'Team'])
            ->assertSee('Sort by Last (A–Z)')
            ->set('sort', 'team')
            ->assertSee('Sort by Team');
    });

    it('sorts by team, then by last name within it', function () {
        // Georgia before Ohio State, and the two Georgia players in surname
        // order inside it.
        $extra = Athlete::create([
            'id' => 555, 'slug' => 'aaron-adams', 'display_name' => 'Aaron Adams',
            'first_name' => 'Aaron', 'last_name' => 'Adams',
        ]);

        AthleteTeamSeason::create([
            'athlete_id' => $extra->id, 'team_id' => 61, 'season_year' => 2026,
            'jersey' => '1', 'position_id' => 1,
        ]);

        Livewire::test('players')
            ->set('sort', 'team')
            ->assertSeeInOrder(['Aaron Adams', 'Gunner Stockton', 'Jeremiah Smith']);
    });

    it('falls back to Last for a sort it does not offer', function () {
        /*
         * Reachable from a querystring, and it reaches the query builder
         * directly — an unknown column there is a 500, not a wrong order. The
         * mount path needs its own guard because `#[Url]` hydrates without ever
         * firing the update hook.
         */
        Livewire::withQueryParams(['sort' => 'nonsense'])
            ->test('players')
            ->assertOk()
            ->assertSet('sort', 'last');

        Livewire::test('players')->set('sort', 'nonsense')->assertSet('sort', 'last');
    });

    it('collapses back to one chunk when the sort changes', function () {
        // A re-sorted list is a different set of people, so however far the
        // reader had loaded means nothing against the new order.
        foreach (range(1000, 1060) as $id) {
            Athlete::create([
                'id' => $id, 'display_name' => "Zed Player {$id}",
                'first_name' => 'Zed', 'last_name' => 'Zulu',
            ]);

            AthleteTeamSeason::create([
                'athlete_id' => $id, 'team_id' => 61, 'season_year' => 2026, 'position_id' => 8,
            ]);
        }

        Livewire::test('players')
            ->call('loadMore')
            ->set('sort', 'name')
            ->assertSet('perPage', 50)
            ->assertSee('Gunner Stockton');
    });
});

it('carries the team logo on the row here, but not in search', function () {
    /*
     * On a screen that is nothing but players the mark is the fastest read of
     * which team each one belongs to. Search results are a mixed list where a
     * team row already carries its own, so a second on every player row is
     * noise — hence the opt-in prop rather than a change to the shared row.
     */
    $this->georgia->update(['logo' => 'https://a.espncdn.com/i/teamlogos/ncaa/500/61.png']);

    expect(Livewire::test('players')->html())
        ->toContain('teamlogos/ncaa/500/61.png');

    expect(Livewire::test('search')->set('q', 'Gunner')->html())
        ->toContain('Gunner Stockton')
        ->not->toContain('teamlogos/ncaa/500/61.png');
});

it('links every player, and nothing else in the row', function () {
    // x-search.row makes the whole row one anchor, so a team link inside it
    // would be a nested <a> — invalid, and it steals the tap.
    $html = Livewire::test('players')->html();

    expect($html)
        ->toContain(route('player', $this->qb))
        ->not->toContain(route('team', $this->georgia));
});
