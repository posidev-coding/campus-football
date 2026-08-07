<?php

use App\Models\Game;
use App\Models\GameOdd;
use App\Models\GamePredictor;
use App\Models\Season;
use App\Models\Team;
use App\Services\Espn\Sync\SyncOdds;
use App\Services\Espn\Sync\SyncPredictors;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    $this->season = Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);
    Team::factory()->create(['id' => 61, 'abbreviation' => 'UGA']);
    Team::factory()->create(['id' => 333, 'abbreviation' => 'BAMA']);

    /*
     * Kickoff pinned deliberately. The factory default is
     * `dateTimeBetween('-4 months', '+1 month')` — random — so this shared
     * fixture landed on an upcoming Saturday roughly one run in seven and was
     * then counted by the "only fetches predictors for upcoming Saturday games"
     * test below, which expects exactly one match. A midweek date well outside
     * the 10-day window keeps it out of every slate-eligible query.
     */
    $this->game = Game::factory()->create([
        'id' => 999,
        'season_id' => $this->season->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
        'completed' => false,
        'kickoff_at' => now()->addMonths(3)->next('Wednesday')->setTime(19, 0),
        'kickoff_day' => 'Wed',
    ]);
});

function oddsCompetition(float $spread, float $overUnder, int $favoriteId = 61): array
{
    return [
        'odds' => [[
            'provider' => ['id' => '100', 'name' => 'DraftKings'],
            'details' => 'UGA -'.abs($spread),
            'spread' => $spread,
            'overUnder' => $overUnder,
            'homeTeamOdds' => ['favorite' => $favoriteId === 61, 'moneyLine' => -280, 'team' => ['id' => '61']],
            'awayTeamOdds' => ['favorite' => $favoriteId === 333, 'moneyLine' => 230, 'team' => ['id' => '333']],
        ]],
    ];
}

it('captures the current line from the scoreboard payload', function () {
    app(SyncOdds::class)->fromCompetition(999, oddsCompetition(-7.5, 48.5));

    $current = GameOdd::where('game_id', 999)->where('phase', GameOdd::CURRENT)->sole();

    expect($current->spread)->toBe(-7.5)
        ->and($current->over_under)->toBe(48.5)
        ->and($current->provider)->toBe('DraftKings')
        ->and($current->favorite_team_id)->toBe(61)
        ->and($current->moneyline_home)->toBe(-280);
});

it('freezes the first line it ever sees as the open and never rewrites it', function () {
    // ESPN's own opening line is not available from the scoreboard, so our
    // first observation becomes the baseline for line movement.
    $sync = app(SyncOdds::class);

    $sync->fromCompetition(999, oddsCompetition(-7.5, 48.5));
    $sync->fromCompetition(999, oddsCompetition(-10.5, 51.5));

    $open = GameOdd::where('game_id', 999)->where('phase', GameOdd::OPEN)->sole();
    $current = GameOdd::where('game_id', 999)->where('phase', GameOdd::CURRENT)->sole();

    expect($open->spread)->toBe(-7.5)
        ->and($current->spread)->toBe(-10.5);

    // The delta is the money proxy that feeds the Game Quality Score.
    expect(abs($open->spread - $current->spread))->toBe(3.0);
});

it('does not record a close until the game is under way', function () {
    $sync = app(SyncOdds::class);

    $sync->fromCompetition(999, oddsCompetition(-7.5, 48.5));
    expect(GameOdd::where('game_id', 999)->where('phase', GameOdd::CLOSE)->exists())->toBeFalse();

    $sync->fromCompetition(999, oddsCompetition(-8.5, 49.5), gameStarted: true);
    expect(GameOdd::where('game_id', 999)->where('phase', GameOdd::CLOSE)->sole()->spread)->toBe(-8.5);
});

it('ignores a provider block with no usable line', function () {
    app(SyncOdds::class)->fromCompetition(999, ['odds' => [[
        'provider' => ['id' => '100', 'name' => 'DraftKings'],
    ]]]);

    expect(GameOdd::count())->toBe(0);
});

it('stores matchup quality for an upcoming game that has no game quality yet', function () {
    /*
     * The distinction that matters for tiering. Verified live: completed games
     * return both metrics, upcoming games return matchupQuality alone, because
     * gameQuality scores how the game turned out. A slate is built before
     * kickoff, so matchupQuality is the one the Game Quality Score can use.
     */
    Http::fake(['*predictor*' => Http::response([
        'homeTeam' => ['statistics' => [
            ['name' => 'matchupQuality', 'value' => 63.56],
            ['name' => 'gameProjection', 'value' => 51.54],
            ['name' => 'oppSeasonStrengthRating', 'value' => 12.4],
        ]],
        'awayTeam' => ['statistics' => [
            ['name' => 'matchupQuality', 'value' => 63.56],
            ['name' => 'gameProjection', 'value' => 48.46],
        ]],
    ])]);

    expect(app(SyncPredictors::class)->game(999))->toBeTrue();

    $predictor = GamePredictor::where('game_id', 999)->sole();

    expect($predictor->matchup_quality)->toBe(63.56)
        ->and($predictor->game_quality)->toBeNull()
        ->and($predictor->home_projection)->toBe(51.54);
});

it('writes nothing when ESPN has not modelled the game', function () {
    Http::fake(['*predictor*' => Http::response('', 404)]);

    expect(app(SyncPredictors::class)->game(999))->toBeFalse()
        ->and(GamePredictor::count())->toBe(0);
});

it('fetches predictors for every upcoming fixture, never completed ones', function () {
    Http::fake(['*predictor*' => Http::response([
        'homeTeam' => ['statistics' => [['name' => 'matchupQuality', 'value' => 50.0]]],
    ])]);

    // Completed: its predictor window has closed and ESPN no longer serves it.
    Game::factory()->finished()->onSaturday()->create(['season_id' => $this->season->id]);

    // A midweek fixture counts now — the game page's matchup predictor
    // renders for a Tuesday MACtion game too, not just the pick'em slate.
    $midweek = Game::factory()->create([
        'season_id' => $this->season->id,
        'completed' => false,
        'kickoff_at' => now()->addDays(2),
        'kickoff_day' => 'Wed',
    ]);

    $saturday = Game::factory()->onSaturday()->create([
        'season_id' => $this->season->id,
        'completed' => false,
    ]);

    expect(app(SyncPredictors::class)->upcoming(days: 10))->toBe(2)
        ->and(GamePredictor::where('game_id', $saturday->id)->exists())->toBeTrue()
        ->and(GamePredictor::where('game_id', $midweek->id)->exists())->toBeTrue();
});

it('can still narrow to slate-eligible Saturdays for the pick a slate is built from', function () {
    Http::fake(['*predictor*' => Http::response([
        'homeTeam' => ['statistics' => [['name' => 'matchupQuality', 'value' => 50.0]]],
    ])]);

    Game::factory()->create([
        'season_id' => $this->season->id,
        'completed' => false,
        'kickoff_at' => now()->addDays(2),
        'kickoff_day' => 'Wed',
    ]);

    $saturday = Game::factory()->onSaturday()->create([
        'season_id' => $this->season->id,
        'completed' => false,
    ]);

    expect(app(SyncPredictors::class)->upcoming(days: 10, saturdayOnly: true))->toBe(1)
        ->and(GamePredictor::where('game_id', $saturday->id)->exists())->toBeTrue();
});

it('stores the projected margin and opponent-strength ranks', function () {
    Http::fake(['*predictor*' => Http::response([
        'homeTeam' => ['statistics' => [
            ['name' => 'gameProjection', 'value' => 51.5],
            ['name' => 'matchupQuality', 'value' => 63.6],
            ['name' => 'teamPredPtDiff', 'value' => 0.5],
            ['name' => 'oppSeasonStrengthRating', 'value' => 4.9],
            ['name' => 'oppSeasonStrengthFbsRank', 'value' => 42],
            // The complement of the projection — must NOT be persisted.
            ['name' => 'teamChanceLoss', 'value' => 48.5],
        ]],
        'awayTeam' => ['statistics' => [
            ['name' => 'gameProjection', 'value' => 48.5],
            ['name' => 'teamPredPtDiff', 'value' => -0.5],
            ['name' => 'oppSeasonStrengthFbsRank', 'value' => 38],
        ]],
    ])]);

    app(SyncPredictors::class)->game(999);

    $predictor = GamePredictor::where('game_id', 999)->sole();

    expect($predictor->home_pred_pt_diff)->toBe(0.5)
        ->and($predictor->away_pred_pt_diff)->toBe(-0.5)
        ->and($predictor->home_opp_strength_rank)->toBe(42)
        ->and($predictor->away_opp_strength_rank)->toBe(38)
        ->and($predictor->getAttributes())->not->toHaveKey('team_chance_loss');
});

it('reaches past the default window when a human asks it to', function () {
    /*
     * The default ten days is the scheduled cadence's, and in the preseason it
     * correctly finds nothing — the 2026 opener was 23 days out when this was
     * written, so a bare run reported "0 records" and looked broken. --days is
     * the way to seed week 1 from August, and it exists because the seeding
     * pass for this feature had to go through tinker to widen a window the
     * command could not.
     */
    Http::fake(['*predictor*' => Http::response([
        'homeTeam' => ['statistics' => [['name' => 'matchupQuality', 'value' => 50.0]]],
    ])]);

    Game::factory()->create([
        'season_id' => $this->season->id,
        'completed' => false,
        'kickoff_at' => now()->addDays(20),
    ]);

    // Inside the default window nothing is in range...
    $this->artisan('cfb:sync --only=predictors')->assertSuccessful();
    expect(GamePredictor::count())->toBe(0);

    // ...and widening it reaches the fixture.
    $this->artisan('cfb:sync --only=predictors --days=25')->assertSuccessful();
    expect(GamePredictor::count())->toBe(1);
});
