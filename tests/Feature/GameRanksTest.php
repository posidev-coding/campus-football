<?php

use App\Models\Game;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Support\GameRanks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->preseason = Season::factory()->create([
        'year' => 2026, 'type' => Season::PRESEASON,
        'start_date' => '2026-02-01', 'end_date' => '2026-08-22',
    ]);

    $this->regular = Season::factory()->create([
        'year' => 2026, 'type' => Season::REGULAR,
        'start_date' => '2026-08-22', 'end_date' => '2026-12-12',
    ]);

    $this->postseason = Season::factory()->create([
        'year' => 2026, 'type' => Season::POSTSEASON,
        'start_date' => '2026-12-13', 'end_date' => '2027-01-21',
    ]);

    // The preseason poll hangs off type 1 week 1 — that is the whole reason
    // week 1 games can show a ranking at all.
    $this->preWeek = Week::create([
        'season_id' => $this->preseason->id, 'number' => 1, 'name' => 'Preseason',
        'start_date' => '2026-08-01', 'end_date' => '2026-08-21',
    ]);

    $this->weeks = [];

    for ($i = 1; $i <= 13; $i++) {
        $start = CarbonImmutable::parse('2026-08-29 07:00', 'UTC')->addWeeks($i - 1);

        $this->weeks[$i] = Week::create([
            'season_id' => $this->regular->id, 'number' => $i, 'name' => "Week {$i}",
            'start_date' => $start, 'end_date' => $start->addWeek()->subSecond(),
        ]);
    }

    $this->bowlWeek = Week::create([
        'season_id' => $this->postseason->id, 'number' => 1, 'name' => 'Bowls',
        'start_date' => '2026-12-13', 'end_date' => '2027-01-21',
    ]);

    $this->vols = Team::factory()->create(['id' => 2633, 'location' => 'Tennessee']);
    $this->cats = Team::factory()->create(['id' => 96, 'location' => 'Kentucky']);
});

function rank(int $seasonId, int $weekId, string $poll, int $teamId, int $rank): void
{
    Ranking::create([
        'season_id' => $seasonId, 'week_id' => $weekId,
        'poll' => $poll, 'team_id' => $teamId, 'rank' => $rank,
    ]);
}

it('shows ESPN\'s curated rank whenever it is populated', function () {
    /*
     * ESPN's own statement about the matchup, re-patched by SyncGames on every
     * pass. Our poll data exists to fill the gap it leaves, not to argue with
     * it — so a disagreement resolves ESPN's way.
     */
    rank($this->regular->id, $this->weeks[5]->id, 'ap', 2633, 1);

    $game = Game::factory()->create([
        'season_id' => $this->regular->id, 'week_id' => $this->weeks[5]->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'kickoff_at' => $this->weeks[5]->start_date->addDays(3),
        'home_rank' => 12, 'away_rank' => null,
    ]);

    expect(GameRanks::forGame($game))->toBe(['home' => 12, 'away' => null]);
});

it('never prints ESPN\'s 99 sentinel as a ranking', function () {
    // v3 stored 99 verbatim and every "is this ranked" check had to know the
    // magic number. Anything outside a poll's 25 is simply not a ranking.
    $game = Game::factory()->create([
        'season_id' => $this->regular->id, 'week_id' => $this->weeks[5]->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'kickoff_at' => $this->weeks[5]->start_date->addDays(3),
        'home_rank' => 99, 'away_rank' => 99,
    ]);

    expect(GameRanks::forGame($game))->toBe(['home' => null, 'away' => null]);
});

it('falls back to our own polls when ESPN curated nothing', function () {
    /*
     * The case this exists for. All 946 of 2026's games carry no curated rank
     * on either side even though the preseason poll is out and we hold all 25
     * rows — ESPN does not backfill a schedule when a poll lands.
     */
    rank($this->regular->id, $this->weeks[5]->id, 'ap', 2633, 4);

    $game = Game::factory()->create([
        'season_id' => $this->regular->id, 'week_id' => $this->weeks[5]->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'kickoff_at' => $this->weeks[5]->start_date->addDays(3),
        'home_rank' => null, 'away_rank' => null,
    ]);

    expect(GameRanks::forGame($game))->toBe(['home' => 4, 'away' => null]);
});

it('reads a week 1 game against the PRESEASON poll', function () {
    /*
     * There is no regular-season week 1 release — the poll that applies to
     * opening weekend is the preseason one, filed under type 1 week 1. It falls
     * out of "latest release at or before kickoff" rather than needing a
     * special case.
     */
    rank($this->preseason->id, $this->preWeek->id, 'coaches', 2633, 7);

    $opener = Game::factory()->create([
        'season_id' => $this->regular->id, 'week_id' => $this->weeks[1]->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'kickoff_at' => $this->weeks[1]->start_date->addDays(2),
        'home_rank' => null, 'away_rank' => null,
    ]);

    expect(GameRanks::forGame($opener))->toBe(['home' => 7, 'away' => null]);
});

it('uses the poll as it stood at KICKOFF, not the latest one', function () {
    /*
     * The whole point of "as of that point in time". A team ranked 25th in
     * week 3 and 2nd by week 12 must read 25 on its week 3 card, or a
     * scoreboard rewrites its own history every time a poll moves.
     */
    rank($this->regular->id, $this->weeks[3]->id, 'ap', 2633, 25);
    rank($this->regular->id, $this->weeks[12]->id, 'ap', 2633, 2);

    $early = Game::factory()->create([
        'season_id' => $this->regular->id, 'week_id' => $this->weeks[3]->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'kickoff_at' => $this->weeks[3]->start_date->addDays(3),
        'home_rank' => null, 'away_rank' => null,
    ]);

    $late = Game::factory()->create([
        'season_id' => $this->regular->id, 'week_id' => $this->weeks[12]->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'kickoff_at' => $this->weeks[12]->start_date->addDays(3),
        'home_rank' => null, 'away_rank' => null,
    ]);

    expect(GameRanks::forGame($early)['home'])->toBe(25)
        ->and(GameRanks::forGame($late)['home'])->toBe(2);
});

it('prefers CFP over AP over Coaches within one release', function () {
    // The same ladder ESPN uses: checked against 2025 week 12, its curated
    // value IS the CFP poll (20/8/3/2) rather than the AP one (19/7/3/2).
    rank($this->regular->id, $this->weeks[12]->id, 'coaches', 2633, 9);

    $game = Game::factory()->create([
        'season_id' => $this->regular->id, 'week_id' => $this->weeks[12]->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'kickoff_at' => $this->weeks[12]->start_date->addDays(3),
        'home_rank' => null, 'away_rank' => null,
    ]);

    expect(GameRanks::forGame($game)['home'])->toBe(9);

    GameRanks::flush();
    Cache::flush();
    rank($this->regular->id, $this->weeks[12]->id, 'ap', 2633, 6);

    expect(GameRanks::forGame($game)['home'])->toBe(6);

    GameRanks::flush();
    Cache::flush();
    rank($this->regular->id, $this->weeks[12]->id, 'cfp', 2633, 4);

    expect(GameRanks::forGame($game)['home'])->toBe(4);
});

it('does not let the postseason Final Rankings leak onto a bowl', function () {
    /*
     * ESPN files the AP and Coaches "Final Rankings" under postseason week 1,
     * whose range OPENS on Dec 13 — so a bowl on Dec 20 would show a poll not
     * published until January. Postseason releases are excluded, which leaves
     * the last regular-season release: the CFP final, which is exactly what a
     * bowl card should carry.
     */
    rank($this->regular->id, $this->weeks[13]->id, 'cfp', 2633, 3);
    rank($this->postseason->id, $this->bowlWeek->id, 'ap', 2633, 20);

    $bowl = Game::factory()->create([
        'season_id' => $this->postseason->id, 'week_id' => $this->bowlWeek->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'kickoff_at' => '2026-12-20 20:00:00',
        'home_rank' => null, 'away_rank' => null,
    ]);

    expect(GameRanks::forGame($bowl)['home'])->toBe(3);
});

it('leaves an unranked team blank rather than inventing a number', function () {
    rank($this->regular->id, $this->weeks[5]->id, 'ap', 2633, 1);

    $game = Game::factory()->create([
        'season_id' => $this->regular->id, 'week_id' => $this->weeks[5]->id,
        'home_team_id' => 96, 'away_team_id' => 96,
        'kickoff_at' => $this->weeks[5]->start_date->addDays(3),
        'home_rank' => null, 'away_rank' => null,
    ]);

    expect(GameRanks::forGame($game))->toBe(['home' => null, 'away' => null]);
});

it('costs one lookup for a whole slate, not one per card', function () {
    /*
     * A scoreboard week is fifty cards reading the same release. If this scaled
     * per card it would be a fifty-query regression on the busiest screen in
     * the app — the same shape HomeTest guards for followed teams.
     */
    rank($this->regular->id, $this->weeks[5]->id, 'ap', 2633, 1);

    $games = Game::factory()->count(20)->create([
        'season_id' => $this->regular->id, 'week_id' => $this->weeks[5]->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'kickoff_at' => $this->weeks[5]->start_date->addDays(3),
        'home_rank' => null, 'away_rank' => null,
    ]);

    GameRanks::flush();
    Cache::flush();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    GameRanks::forGame($games->first());
    $cold = $queries;

    foreach ($games->skip(1) as $game) {
        GameRanks::forGame($game);
    }

    /*
     * Asserted as CONSTANCY rather than against a number: what matters is that
     * the 19 cards after the first are free, not how many lookups the first one
     * takes. A fixed budget would just have to be edited every time the
     * resolver changes shape, which is how a guard stops guarding.
     */
    expect($queries)->toBe($cold)
        ->and($cold)->toBeGreaterThan(0);
});
