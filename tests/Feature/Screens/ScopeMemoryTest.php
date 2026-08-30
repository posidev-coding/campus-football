<?php

use App\Models\Conference;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\Week;
use App\Support\Navigation;
use App\Support\Scope;
use Livewire\Livewire;

/*
 * The scope filter remembers its last pick for the whole session — one key,
 * every screen that speaks the vocabulary — so navigating away from Scores
 * and back does not reset SEC to the default.
 *
 * The memory is vetted at the READING end: a screen adopts the remembered
 * value only when its own menu lists it, because filter-menu renders an
 * unlisted selection as its FIRST option's label — a control reading "Top 25"
 * over one conference's games is worse than forgetting the pick.
 */
beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);

    $this->week = Week::create([
        'season_id' => $this->season->id,
        'number' => 5,
        'name' => 'Week 5',
        'start_date' => '2025-09-23',
        'end_date' => '2025-09-29',
    ]);

    Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC']);
    Conference::factory()->create(['id' => 30, 'name' => 'Southern Conference', 'short_name' => 'SoCon']);

    $this->vols = Team::factory()->create(['id' => 2633, 'location' => 'Tennessee', 'display_name' => 'Tennessee Volunteers']);
    $this->mocs = Team::factory()->create(['id' => 236, 'location' => 'Chattanooga', 'display_name' => 'Chattanooga Mocs']);

    TeamSeason::create(['team_id' => 2633, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);
    TeamSeason::create(['team_id' => 236, 'season_year' => 2025, 'conference_id' => 30, 'classification' => 'FCS']);
});

/** The two buckets these screens actually write, named the way the app is. */
function scoresKey(): string
{
    return Scope::sessionKey('scoreboard');
}

function leagueKey(): string
{
    return Scope::sessionKey('stats');
}

it('files a pick under its own area, not one key for the app', function () {
    expect(scoresKey())->toBe('scope.selected.scores')
        ->and(leagueKey())->toBe('scope.selected.league')
        // Every League screen shares the one bucket — inside an area the
        // filter really is asking a single question.
        ->and(Scope::sessionKey('standings'))->toBe(leagueKey())
        ->and(Scope::sessionKey('teams'))->toBe(leagueKey())
        ->and(Scope::sessionKey('players'))->toBe(leagueKey())
        ->and(Scope::sessionKey('recruiting'))->toBe(leagueKey());
});

it('takes the grouping from Navigation rather than a second copy of it', function () {
    // Asked of the real navigation, so moving a screen between areas moves
    // its memory with it instead of stranding the pick in the old bucket.
    $league = collect(Navigation::areas())->firstWhere('key', 'league');

    foreach ($league['routes'] as $route) {
        expect(Scope::areaOf($route))->toBe('league');
    }

    // ...and a route no area claims gets a bucket of its own. Guessing an
    // area is how two unrelated screens overwrite each other's filter.
    expect(Scope::areaOf('nowhere'))->toBe('nowhere');
});

it('remembers a scope picked on the scoreboard', function () {
    Livewire::test('scoreboard')->set('scope', '8');

    expect(session(scoresKey()))->toBe('8')
        // ...and says nothing about League.
        ->and(session(leagueKey()))->toBeNull();
});

it('opens the scoreboard on the remembered scope when the URL carries none', function () {
    session([scoresKey() => '8']);

    Livewire::test('scoreboard')->assertSet('scope', '8');

    // Adopting the value is a read, not a re-choosing — the entry is untouched.
    expect(session(scoresKey()))->toBe('8');
});

it('lets a URL scope beat the session, without rewriting it', function () {
    // A shared link must show what it says, whatever the visitor last picked —
    // and merely following it is not a selection, so the memory stays theirs.
    session([scoresKey() => '8']);

    Livewire::withQueryParams(['scope' => Scope::FBS])
        ->test('scoreboard')
        ->assertSet('scope', Scope::FBS);

    expect(session(scoresKey()))->toBe('8');
});

it('holds Scores and League apart, each remembering its own pick', function () {
    /*
     * The whole feature, in the order a person actually does it: SEC on the
     * scoreboard, All FBS in League, then back to each. One memory made the
     * second pick silently retune the first — the same filter answering two
     * different questions ("whose games are on Saturday" vs "whose season am
     * I reading"), which are routinely different answers held at once.
     */
    Livewire::test('scoreboard')->set('scope', '8');
    Livewire::test('stats')->set('scope', Scope::FBS);

    Livewire::test('scoreboard')->assertSet('scope', '8');
    Livewire::test('stats')->assertSet('scope', Scope::FBS);
});

it('carries a pick across the screens of one area', function () {
    // Inside League it IS one question: narrowing Standings to the SEC and
    // finding Stats already there is the point of remembering at all.
    Livewire::test('teams')->set('scope', '8');

    Livewire::test('stats')->assertSet('scope', '8');
    Livewire::test('players')->assertSet('scope', '8');
    Livewire::test('standings')->assertSet('scope', '8');
});

it('never lets one area seed an area that has chosen nothing', function () {
    // Scores opens on its own default, not on League's pick — an empty
    // memory is not an invitation to borrow the neighbour's.
    Livewire::test('stats')->set('scope', '8');

    Livewire::test('scoreboard')->assertSet('scope', Scope::FBS);
});

it('refuses a remembered scope the reading menu cannot speak', function () {
    // FCS is real on Teams and meaningless on Stats, whose menu never lists
    // it — adopted anyway, the control would read "All FBS" over an FCS list.
    session([leagueKey() => Scope::FCS]);

    Livewire::test('stats')->assertSet('scope', Scope::FBS);

    // And the entry survives one screen declining it: Teams still honors it.
    Livewire::test('teams')->assertSet('scope', Scope::FCS);
});

it('refuses a remembered Top 25 on the leaderboard screens', function () {
    Ranking::create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'poll' => 'ap', 'team_id' => 2633, 'rank' => 1, 'record' => '5-0',
    ]);

    // Top 25 can only have been picked on Scores, so it is Scores' bucket it
    // would have to leak out of — which is now a second reason it cannot.
    session([leagueKey() => Scope::TOP_25, scoresKey() => Scope::TOP_25]);

    Livewire::test('players')->assertSet('scope', Scope::FBS);

    Livewire::test('scoreboard')->assertSet('scope', Scope::TOP_25);
});

it('refuses a remembered Top 25 while the season has no poll', function () {
    // The disabled summer option: adopting it would put "Top 25" on the
    // control while resolving to every FBS team — the exact lie the disabled
    // state exists to prevent.
    session([scoresKey() => Scope::TOP_25]);

    Livewire::test('scoreboard')->assertSet('scope', Scope::FBS);
});

it('remembers the division tabs on standings too', function () {
    // The FBS | FCS tabs write $scope like the menus do, so flipping the
    // division is a pick like any other.
    Livewire::test('standings')->set('scope', Scope::FCS);

    expect(session(leagueKey()))->toBe(Scope::FCS);

    session([leagueKey() => '8']);

    Livewire::test('standings')->assertSet('scope', '8');
});

it('wires the memory into every screen wearing the scope filter', function () {
    // A screen that renders the filter but skips the session pair silently
    // forgets picks made elsewhere — and its own picks vanish everywhere else.
    $screens = collect(glob(resource_path('views/livewire/*.blade.php')))
        ->filter(fn (string $path) => str_contains(file_get_contents($path), '<x-scope-filter'))
        // Standings speaks the same vocabulary through its own division tabs
        // and conference menu rather than x-scope-filter.
        ->push(resource_path('views/livewire/standings.blade.php'));

    expect($screens)->not->toBeEmpty();

    foreach ($screens as $path) {
        $source = file_get_contents($path);

        expect($source)->toContain('Scope::remembered(')
            ->and($source)->toContain('Scope::remember(');

        /*
         * ...and the route it files under must be one Navigation actually
         * knows. A typo would not throw: it would fall through to a private
         * bucket of its own and quietly forget every pick made next door,
         * which is exactly the bug this whole change is fixing.
         */
        preg_match("/Scope::remembered\(\s*'([a-z0-9.\-]+)'/", $source, $matches);

        expect($matches[1] ?? null)->not->toBeNull()
            ->and(Scope::areaOf($matches[1]))->not->toBe($matches[1]);
    }
});
