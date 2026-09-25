<?php

use App\Enums\MatchupHeat;
use App\Models\Conference;
use App\Models\Game;
use App\Models\GamePredictor;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\Week;
use App\Support\Scope;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The heat ring on a game card: ESPN's matchup quality blended with how close
 * its projection is, drawn in the whitespace an upcoming game's score column
 * leaves. Three disciplines: a blowout between good teams is never fire; an
 * unmodelled game gets no ring rather than a cold one; and a screen of cards
 * reads the predictors once.
 */

function heatPredictor(?float $quality, ?float $homeProjection): GamePredictor
{
    return new GamePredictor([
        'matchup_quality' => $quality,
        'home_projection' => $homeProjection,
        'away_projection' => $homeProjection === null ? null : 100 - $homeProjection,
    ]);
}

describe('the score', function () {
    it('ranks a close game between good teams above a blowout between good teams', function () {
        // Real 2026 rows. ESPN's matchup quality alone has these two games
        // within two points of each other.
        $pickem = MatchupHeat::score(heatPredictor(74.36, 48.48));   // SMU at Florida State, 3
        $blowout = MatchupHeat::score(heatPredictor(72.47, 94.60));  // ECU at Alabama, 27.5

        expect($pickem)->toBe(86)
            ->and(MatchupHeat::fromScore($pickem))->toBe(MatchupHeat::Fire)
            ->and($blowout)->toBe(38)
            ->and(MatchupHeat::fromScore($blowout))->toBe(MatchupHeat::Mild);
    });

    it('keeps an ordinary favorite in a big game on fire', function () {
        /*
         * Week 4's real rows, and the reason closeness is a curve. Texas at
         * Tennessee was the strongest matchup of the week on a 4.5-point line,
         * but ESPN's 70/30 projection cost it 40% of its closeness on a
         * straight line — 77, a tier below two coin flips. A 4.5-point game
         * is a close game.
         */
        $favorite = MatchupHeat::score(heatPredictor(98.90, 29.74));   // Texas at Tennessee, 4.5
        $coinFlip = MatchupHeat::score(heatPredictor(91.21, 53.18));   // Ole Miss at Florida, 3.5

        expect($favorite)->toBe(91)
            ->and(MatchupHeat::fromScore($favorite))->toBe(MatchupHeat::Fire)
            ->and($coinFlip)->toBe(95);
    });

    it('calls a big blowout a snooze, even between decent teams', function () {
        // Sam Houston at Texas Tech, Week 4, 34.5 — the curve's gentleness
        // with favorites lifts it into the twenties, still under the line.
        $score = MatchupHeat::score(heatPredictor(59.92, 96.63));

        expect($score)->toBe(28)
            ->and(MatchupHeat::fromScore($score))->toBe(MatchupHeat::Snooze);
    });

    it('calls an FCS visit a snooze, whoever the home team is', function () {
        // Ball State at Ohio State, 49.5 — ESPN's quality 52.6, above half.
        $score = MatchupHeat::score(heatPredictor(52.64, 99.73));

        expect($score)->toBe(8)
            ->and(MatchupHeat::fromScore($score))->toBe(MatchupHeat::Snooze);
    });

    it('is symmetric about a coin flip', function () {
        expect(MatchupHeat::score(heatPredictor(60, 80)))
            ->toBe(MatchupHeat::score(heatPredictor(60, 20)));
    });

    it('is null, never zero, when either input is missing', function () {
        expect(MatchupHeat::score(null))->toBeNull()
            ->and(MatchupHeat::score(heatPredictor(null, 50)))->toBeNull()
            ->and(MatchupHeat::score(heatPredictor(80, null)))->toBeNull();
    });

    it('puts every tier boundary where it says', function (int $score, MatchupHeat $heat) {
        expect(MatchupHeat::fromScore($score))->toBe($heat);
    })->with([
        [100, MatchupHeat::Fire],
        [80, MatchupHeat::Fire],
        [79, MatchupHeat::Warm],
        [45, MatchupHeat::Warm],
        [44, MatchupHeat::Mild],
        [30, MatchupHeat::Mild],
        [29, MatchupHeat::Snooze],
        [0, MatchupHeat::Snooze],
    ]);
});

describe('the card', function () {
    beforeEach(function () {
        $this->season = Season::factory()->create([
            'year' => 2025, 'type' => Season::REGULAR,
            'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
        ]);
        $this->week = Week::create([
            'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
            'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
        ]);

        $conference = Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC']);
        Team::factory()->create(['id' => 2633, 'location' => 'Tennessee', 'display_name' => 'Tennessee Volunteers']);
        Team::factory()->create(['id' => 333, 'location' => 'Alabama', 'display_name' => 'Alabama Crimson Tide']);

        foreach ([2633, 333] as $teamId) {
            TeamSeason::create([
                'team_id' => $teamId, 'season_year' => 2025,
                'conference_id' => $conference->id, 'classification' => 'FBS',
            ]);
        }

        $this->game = fn (array $attributes = []) => Game::factory()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 2633, 'away_team_id' => 333,
            'kickoff_at' => '2025-09-27 19:30:00',
            ...$attributes,
        ]);
    });

    it('wears the flame on an upcoming must-watch game', function () {
        $game = ($this->game)();
        $game->predictor()->create(['matchup_quality' => 88, 'home_projection' => 52, 'away_projection' => 48]);

        $html = Blade::render('<x-game-card :game="$game" />', ['game' => $game->fresh()]);

        expect($html)
            ->toContain('data-heat="fire"')
            ->toContain('aria-label="Must-watch matchup, 94 of 100"')
            ->toContain('M8 16c3.314');   // the flame's path, and only the flame's
    });

    it('wears the moon on a snooze', function () {
        $game = ($this->game)();
        $game->predictor()->create(['matchup_quality' => 40, 'home_projection' => 99, 'away_projection' => 1]);

        $html = Blade::render('<x-game-card :game="$game" />', ['game' => $game->fresh()]);

        expect($html)
            ->toContain('data-heat="snooze"')
            ->toContain('M6 .278a.77.77')     // the moon's path
            ->not->toContain('M8 16c3.314');
    });

    it('draws no ring at all for a game ESPN has not modelled', function () {
        $game = ($this->game)();

        $html = Blade::render('<x-game-card :game="$game" />', ['game' => $game->fresh()]);

        expect($html)->not->toContain('data-heat=');
    });

    it('gives the space back to the score once a game is played', function () {
        $game = ($this->game)(['completed' => true, 'status' => 'post', 'home_score' => 31, 'away_score' => 17]);
        $game->predictor()->create(['matchup_quality' => 88, 'home_projection' => 52, 'away_projection' => 48]);

        $html = Blade::render('<x-game-card :game="$game" />', ['game' => $game->fresh()]);

        expect($html)->not->toContain('data-heat=');
    });

    it('gives the space back to the score while a game is live', function () {
        $game = ($this->game)(['status' => 'in', 'home_score' => 7, 'away_score' => 3]);
        $game->predictor()->create(['matchup_quality' => 88, 'home_projection' => 52, 'away_projection' => 48]);

        $html = Blade::render('<x-game-card :game="$game" />', ['game' => $game->fresh()]);

        expect($html)->not->toContain('data-heat=');
    });

    it('reads a whole scoreboard of predictors in one batch, never per card', function () {
        foreach ([88, 50, 20] as $quality) {
            ($this->game)()->predictor()->create([
                'matchup_quality' => $quality, 'home_projection' => 55, 'away_projection' => 45,
            ]);
        }

        DB::enableQueryLog();

        $html = Livewire::test('scoreboard')
            ->set('scope', Scope::FBS)
            ->set('week', $this->week->id)
            ->html();

        $reads = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, '`game_predictors`'));

        DB::disableQueryLog();

        /*
         * The screen recomputes its games on each Livewire round trip, so the
         * batch runs once per render, not once per test. What must never
         * appear is the card's fallback — `where game_id = ? limit 1`, one per
         * card — which is what a missing eager load costs.
         */
        expect(substr_count($html, 'data-heat="'))->toBe(3)
            ->and($reads)->not->toBeEmpty()
            ->and($reads->reject(fn (string $query) => str_contains($query, '`game_id` in (')))->toBeEmpty();
    });
});

it('eager loads the predictor wherever a game card is rendered', function () {
    /*
     * A SOURCE sweep, for the reason RailTest gives: an unloaded relation
     * resolves silently in a test and throws only in dev and production. The
     * card falls back to a query rather than a 500, so here the cost of a
     * miss is a query per card — which the scoreboard test above counts, and
     * this holds for every other surface.
     *
     * `x-scoreboard-day` renders the cards but receives them; the screen that
     * renders IT owns the query.
     */
    $violations = [];

    foreach ([...glob(resource_path('views/livewire/*.blade.php')), ...glob(resource_path('views/components/*/*.blade.php')), ...glob(resource_path('views/components/*.blade.php'))] as $path) {
        if (str_ends_with($path, 'scoreboard-day.blade.php')) {
            continue;
        }

        $source = file_get_contents($path);

        if (! str_contains($source, '<x-game-card') && ! str_contains($source, '<x-scoreboard-day')) {
            continue;
        }

        if (! str_contains($source, "'predictor'")) {
            $violations[] = str_replace(resource_path('views/'), '', $path);
        }
    }

    expect($violations)->toBe([], implode(', ', $violations)
        .' render <x-game-card> without eager loading its predictor.');
});
