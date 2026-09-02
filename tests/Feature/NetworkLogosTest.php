<?php

use App\Models\Conference;
use App\Models\Game;
use App\Models\Network;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGames;
use App\Support\Networks;
use App\Support\Scope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * A NETWORK'S MARK where a caption used to spell its name — and the name
 * where ESPN ships no artwork. Three disciplines: the marks arrive on the
 * scoreboard request the scores already came on (nothing against the
 * tiers); a payload that names a network WITHOUT a logo never nulls the one
 * we hold ("never write a default when a feed returns nothing", one column
 * at a time); and a screen of cards reads the map once.
 */

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    $this->season = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);
    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    // Membership is season-scoped, and the FBS scope resolves through it.
    $conference = Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC']);
    Team::factory()->create(['id' => 2633, 'location' => 'Tennessee', 'display_name' => 'Tennessee Volunteers']);
    Team::factory()->create(['id' => 333, 'location' => 'Alabama', 'display_name' => 'Alabama Crimson Tide']);

    foreach ([2633, 333] as $teamId) {
        TeamSeason::create([
            'team_id' => $teamId,
            'season_year' => 2025,
            'conference_id' => $conference->id,
            'classification' => 'FBS',
        ]);
    }
});

/** One upcoming scoreboard event, with the geoBroadcasts block the marks ride in. */
function networkScoreboardEvent(int $id, array $names, array $geoBroadcasts): array
{
    return [
        'id' => (string) $id,
        'date' => '2025-09-27T23:30Z',
        'name' => 'Alabama Crimson Tide at Tennessee Volunteers',
        'shortName' => 'ALA @ TENN',
        'season' => ['year' => 2025, 'type' => 2],
        'status' => [
            'period' => 0,
            'displayClock' => '0:00',
            'type' => ['state' => 'pre', 'completed' => false, 'shortDetail' => '9/27 - 7:30 PM EDT'],
        ],
        'competitions' => [[
            'neutralSite' => false,
            'conferenceCompetition' => true,
            'broadcasts' => [['market' => 'national', 'names' => $names]],
            'geoBroadcasts' => $geoBroadcasts,
            'competitors' => [
                ['id' => '2633', 'homeAway' => 'home', 'score' => '0', 'curatedRank' => ['current' => 99]],
                ['id' => '333', 'homeAway' => 'away', 'score' => '0', 'curatedRank' => ['current' => 99]],
            ],
        ]],
    ];
}

/** ESPN's real shape: `media` carries a logo pair for its own family and only a name for everyone else. */
function networkGeoBroadcast(string $name, ?string $logo = null, ?string $darkLogo = null): array
{
    return [
        'type' => ['id' => '1', 'shortName' => 'TV'],
        'market' => ['id' => '1', 'type' => 'National'],
        'media' => array_filter(
            ['shortName' => $name, 'logo' => $logo, 'darkLogo' => $darkLogo],
            fn ($value) => $value !== null,
        ),
        'lang' => 'en',
        'region' => 'us',
    ];
}

it('learns the marks on the scoreboard request the scores came on', function () {
    Queue::fake();

    Http::fake(['*scoreboard*' => Http::response(['events' => [
        networkScoreboardEvent(401, ['ESPN'], [
            // "" is ESPN saying the light mark serves both surfaces.
            networkGeoBroadcast('ESPN', 'https://a.espncdn.com/guid/espn/logos/default.png', ''),
            networkGeoBroadcast('ABC', 'https://a.espncdn.com/guid/abc/logos/default.png', 'https://a.espncdn.com/guid/abc/logos/default-dark.png'),
            networkGeoBroadcast('FOX'),
        ]),
    ]])]);

    app(SyncGames::class)->week($this->week);

    Http::assertSentCount(1);

    $espn = Network::query()->where('name', 'ESPN')->first();
    $abc = Network::query()->where('name', 'ABC')->first();
    $fox = Network::query()->where('name', 'FOX')->first();

    expect($espn->logo)->toBe('https://a.espncdn.com/guid/espn/logos/default.png')
        ->and($espn->logo_dark)->toBeNull()
        ->and($abc->logo)->toBe('https://a.espncdn.com/guid/abc/logos/default.png')
        ->and($abc->logo_dark)->toBe('https://a.espncdn.com/guid/abc/logos/default-dark.png')
        // Named, so the row exists — and no artwork invented for it.
        ->and($fox)->not->toBeNull()
        ->and($fox->logo)->toBeNull()
        ->and($fox->logo_dark)->toBeNull();
});

it('never nulls a mark because one payload did not carry it', function () {
    /*
     * The break-it-back case. A `fill()` of the logo columns off every
     * media entry would pass the test above and quietly erase ESPN's mark
     * on the first payload that named it bare — which the live tier does
     * sixty times an hour.
     */
    Queue::fake();
    Network::factory()->create(['name' => 'ESPN', 'logo' => 'https://a.espncdn.com/guid/espn/logos/default.png']);

    Http::fake(['*scoreboard*' => Http::response(['events' => [
        networkScoreboardEvent(401, ['ESPN'], [networkGeoBroadcast('ESPN')]),
    ]])]);

    app(SyncGames::class)->week($this->week);

    expect(Network::query()->where('name', 'ESPN')->value('logo'))
        ->toBe('https://a.espncdn.com/guid/espn/logos/default.png')
        ->and(Network::query()->count())->toBe(1);
});

it('forgets the day-long map the moment the sync learns a mark', function () {
    Queue::fake();
    Network::factory()->create(['name' => 'ABC', 'logo' => 'https://a.espncdn.com/guid/abc/logos/default.png']);

    // Read once, so the map is cached and memoized before the sync runs.
    expect(Networks::all())->toHaveKey('ABC')->not->toHaveKey('ESPN')
        ->and(Cache::has(Networks::CACHE_KEY))->toBeTrue();

    Http::fake(['*scoreboard*' => Http::response(['events' => [
        networkScoreboardEvent(401, ['ESPN'], [
            networkGeoBroadcast('ESPN', 'https://a.espncdn.com/guid/espn/logos/default.png', ''),
        ]),
    ]])]);

    app(SyncGames::class)->week($this->week);

    expect(Networks::mark('ESPN'))->toBe(['logo' => 'https://a.espncdn.com/guid/espn/logos/default.png', 'logo_dark' => null])
        ->and(Networks::mark('FOX'))->toBeNull();
});

it('wears the mark on a game card, and the name where ESPN ships none, off one read', function () {
    Network::factory()->create(['name' => 'ESPN', 'logo' => 'https://a.espncdn.com/guid/espn/logos/default.png']);
    Network::factory()->create([
        'name' => 'ABC',
        'logo' => 'https://a.espncdn.com/guid/abc/logos/default.png',
        'logo_dark' => 'https://a.espncdn.com/guid/abc/logos/default-dark.png',
    ]);

    foreach ([['ESPN'], ['ABC'], ['FOX']] as $names) {
        Game::factory()->create([
            'season_id' => $this->season->id,
            'week_id' => $this->week->id,
            'home_team_id' => 2633,
            'away_team_id' => 333,
            'kickoff_at' => '2025-09-27 19:30:00',
            'broadcasts' => $names,
        ]);
    }

    // Cold, so the count below is the screen's own read and not this test's.
    Networks::flush();
    Cache::forget(Networks::CACHE_KEY);

    DB::enableQueryLog();

    $html = Livewire::test('scoreboard')
        ->set('scope', Scope::FBS)
        ->set('week', $this->week->id)
        ->html();

    $reads = collect(DB::getQueryLog())
        ->filter(fn (array $query) => str_contains($query['query'], '`networks`'))
        ->count();

    DB::disableQueryLog();

    expect($html)
        ->toContain('src="https://a.espncdn.com/guid/espn/logos/default.png"')
        ->toContain('alt="ESPN"')
        // ESPN's own dark variant where it sent one, the x-team-logo grammar.
        ->toContain('src="https://a.espncdn.com/guid/abc/logos/default-dark.png"')
        ->toContain('dark:inline-block')
        // The name, as text, for a network with no mark — never a broken image.
        ->toContain('FOX')
        ->not->toContain('alt="FOX"')
        // Three cards, one map.
        ->and($reads)->toBe(1);
});
