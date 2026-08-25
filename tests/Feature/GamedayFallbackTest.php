<?php

use App\Ai\Agents\GamedaySite;
use App\Enums\AiModel;
use App\Models\AiSpend;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\Venue;
use App\Support\GamedayFallback;
use Carbon\CarbonImmutable;

/*
 * The fallback fires a handful of times a season, which is exactly why its
 * guards need tests: nobody is watching the week it runs, and a plausible
 * wrong campus on the home page is the expensive kind of error.
 *
 * Every rejection below returns null, and the caller writes `unknown`. On a
 * feature whose whole job is producing a location, "we do not know" has to be
 * the cheaper outcome.
 */

beforeEach(function () {
    config()->set('cfb.ai_enabled', true);

    $this->season = Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);
    $this->lsu = Team::factory()->create(['display_name' => 'LSU Tigers', 'location' => 'LSU']);
    $venue = Venue::create(['id' => 99001, 'name' => 'Tiger Stadium', 'city' => 'Baton Rouge', 'state' => 'LA']);

    $this->game = Game::factory()->create([
        'season_id' => $this->season->id,
        'venue_id' => $venue->id,
        'home_team_id' => $this->lsu->id,
        'kickoff_at' => '2026-09-05 19:30:00',
    ]);

    $this->saturday = CarbonImmutable::parse('2026-09-05', config('cfb.timezone'));
});

function gamedayAnswer(array $overrides = []): array
{
    return [
        'announced' => true,
        'site' => 'Tiger Stadium',
        'city' => 'Baton Rouge',
        'state' => 'LA',
        'host_team_name' => 'LSU',
        'game_hint' => 'Clemson at LSU',
        'confidence' => 0.9,
        'source_url' => 'https://www.espn.com/college-football/story/_/id/1/gameday-week-1',
        ...$overrides,
    ];
}

it('never calls the model while the layer is switched off', function () {
    // The master switch and the ceiling are ONE question, so a caller cannot
    // check the money and forget the switch.
    config()->set('cfb.ai_enabled', false);
    GamedaySite::fake();

    [$proposal] = app(GamedayFallback::class)->attempt($this->saturday);

    expect($proposal)->toBeNull();
    GamedaySite::assertNeverPrompted();
});

it('never calls the model once the month is spent', function () {
    config()->set('cfb.ai_monthly_budget', 1);
    AiSpend::create([
        'model' => AiModel::Sonnet5->value,
        'feature' => 'recap',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 1.5,
    ]);
    GamedaySite::fake();

    [$proposal, $reason] = app(GamedayFallback::class)->attempt($this->saturday);

    expect($proposal)->toBeNull()->and($reason)->not->toBe('resolved');
    GamedaySite::assertNeverPrompted();
});

it('accepts an answer our own schedule agrees with', function () {
    GamedaySite::fake([gamedayAnswer()]);

    [$proposal] = app(GamedayFallback::class)->attempt($this->saturday);

    expect($proposal['game_id'])->toBe($this->game->id)
        ->and($proposal['team_id'])->toBe($this->lsu->id)
        // The venue name comes from OUR data, never from the model's `site`.
        ->and($proposal['site'])->toBe('Tiger Stadium')
        ->and($proposal['confidence'])->toBe(0.9);
});

it('discards an answer that cites nothing', function () {
    // Search is mandatory; parametric memory is not a source. The location
    // changes weekly, so anything remembered is stale or another season.
    GamedaySite::fake([gamedayAnswer(['source_url' => ''])]);

    [$proposal] = app(GamedayFallback::class)->attempt($this->saturday);

    expect($proposal)->toBeNull();
});

it('takes not-yet-announced for an answer', function () {
    GamedaySite::fake([gamedayAnswer(['announced' => false])]);

    expect(app(GamedayFallback::class)->attempt($this->saturday)[0])->toBeNull();
});

it('rejects a campus with no game on it, which is the strongest guard', function () {
    /*
     * Deterministic and free: GameDay broadcasts from a campus hosting a
     * game, so a place nothing is played at that Saturday contradicts the
     * database outright. It catches the most likely hallucination without
     * spending anything to do it.
     */
    GamedaySite::fake([gamedayAnswer(['city' => 'Norman', 'state' => 'OK', 'host_team_name' => 'Oklahoma'])]);

    expect(app(GamedayFallback::class)->attempt($this->saturday)[0])->toBeNull();
});

it('rejects the right city under the wrong school', function () {
    // A right city with the wrong school is the shape a plausible
    // hallucination takes, and the city alone would have waved it through.
    Team::factory()->create(['display_name' => 'Oklahoma Sooners', 'location' => 'Oklahoma']);

    GamedaySite::fake([gamedayAnswer(['host_team_name' => 'Oklahoma'])]);

    [$proposal, $reason] = app(GamedayFallback::class)->attempt($this->saturday);

    expect($proposal)->toBeNull()
        ->and($reason)->toContain('LSU Tigers');
});

it('charges the call whatever the guards then decide', function () {
    // The tokens were spent either way. A budget that only counts the calls
    // it liked undercounts exactly when something is going wrong.
    GamedaySite::fake([gamedayAnswer(['announced' => false])]);

    app(GamedayFallback::class)->attempt($this->saturday);

    expect(AiSpend::where('feature', 'gameday')->count())->toBe(1);
});
