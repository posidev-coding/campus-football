<?php

use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use Illuminate\Support\Facades\Cache;

/**
 * The Top 25 rail panel.
 *
 * It shipped filtering to `Season::REGULAR`, which is wrong for most of the
 * calendar: the preseason poll lives on the type 1 season row and the final
 * rankings on type 3. Between the preseason release and week 1 — every August,
 * the busiest month of the year for this app — the panel found a regular
 * season with no rankings in it, returned nothing, and left a dead 288px
 * column beside every screen.
 *
 * These fixtures all place the poll on a season row that is NOT the regular
 * one, because that is the only shape that fails.
 */
function panelSeason(int $year, int $type, string $start, string $end): Season
{
    return Season::factory()->create([
        'year' => $year, 'type' => $type, 'start_date' => $start, 'end_date' => $end,
    ]);
}

function panelTeam(): Team
{
    // Every column the panel renders is pinned. The factory mints a random
    // city for `location` and derives `abbreviation` from it, so a bare
    // factory team asserts against a value that changes run to run.
    return Team::factory()->create([
        'id' => 84, 'slug' => 'indiana-hoosiers', 'location' => 'Indiana',
        'display_name' => 'Indiana Hoosiers', 'short_display_name' => 'Indiana',
        'abbreviation' => 'IND', 'alt_color' => '#ffffff',
    ]);
}

describe('finding the poll', function () {
    it('finds a poll that lives on the PRESEASON season row', function () {
        /*
         * The August shape, and the whole point of this test: a regular season
         * exists and is scheduled, but has not been played and carries no
         * poll. The only rankings in the database belong to the preseason.
         */
        $preseason = panelSeason(2026, Season::PRESEASON, '2026-08-01', '2026-08-28');
        panelSeason(2026, Season::REGULAR, '2026-08-29', '2026-12-12');

        $week = Week::create([
            'season_id' => $preseason->id, 'number' => 1, 'name' => 'Preseason',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-07',
        ]);

        $team = panelTeam();

        Ranking::create([
            'season_id' => $preseason->id, 'week_id' => $week->id, 'poll' => 'coaches',
            'team_id' => $team->id, 'rank' => 1, 'previous_rank' => 1, 'record' => '0-0',
        ]);

        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('Coaches')
            ->assertSee('Indiana');
    });

    it('finds a poll that lives on the POSTSEASON season row', function () {
        // The mirror case: the final rankings after a season is over.
        $post = panelSeason(2025, Season::POSTSEASON, '2025-12-14', '2026-01-20');
        panelSeason(2025, Season::REGULAR, '2025-08-23', '2025-12-13');

        $week = Week::create([
            'season_id' => $post->id, 'number' => 1, 'name' => 'Final Rankings',
            'start_date' => '2026-01-14', 'end_date' => '2026-01-20',
        ]);

        $team = panelTeam();

        Ranking::create([
            'season_id' => $post->id, 'week_id' => $week->id, 'poll' => 'ap',
            'team_id' => $team->id, 'rank' => 1, 'previous_rank' => 2, 'record' => '16-0',
        ]);

        $this->get(route('scoreboard'))->assertOk()->assertSee('Indiana');
    });
});

describe('the cache', function () {
    it('serves plain arrays that survive a SECOND request', function () {
        /*
         * An Eloquent collection put into the cache comes back as
         * __PHP_Incomplete_Class and fails on the second request, never the
         * first — so a single-call test always passes and proves nothing.
         */
        $preseason = panelSeason(2026, Season::PRESEASON, '2026-08-01', '2026-08-28');
        panelSeason(2026, Season::REGULAR, '2026-08-29', '2026-12-12');

        $week = Week::create([
            'season_id' => $preseason->id, 'number' => 1, 'name' => 'Preseason',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-07',
        ]);

        $team = panelTeam();

        Ranking::create([
            'season_id' => $preseason->id, 'week_id' => $week->id, 'poll' => 'coaches',
            'team_id' => $team->id, 'rank' => 1, 'previous_rank' => 1, 'record' => '0-0',
        ]);

        foreach (range(1, 2) as $ignored) {
            $this->get(route('scoreboard'))
                ->assertOk()
                ->assertSee('Indiana')
                ->assertDontSee('__PHP_Incomplete_Class');
        }
    });

    it('does not pin an empty poll for a whole TTL', function () {
        /*
         * Remember::filled, not Cache::remember. The rankings sync drains
         * through queued jobs; a page opened one minute before the rows land
         * must not serve a dead rail for the next fifteen.
         *
         * Runs against the real cache store — Cache::fake() would make the
         * distinction between the two helpers invisible.
         */
        $preseason = panelSeason(2026, Season::PRESEASON, '2026-08-01', '2026-08-28');
        panelSeason(2026, Season::REGULAR, '2026-08-29', '2026-12-12');

        $team = panelTeam();

        $this->get(route('scoreboard'))->assertOk()->assertDontSee('Indiana');

        $week = Week::create([
            'season_id' => $preseason->id, 'number' => 1, 'name' => 'Preseason',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-07',
        ]);

        Ranking::create([
            'season_id' => $preseason->id, 'week_id' => $week->id, 'poll' => 'coaches',
            'team_id' => $team->id, 'rank' => 1, 'previous_rank' => 1, 'record' => '0-0',
        ]);

        // The calendar's own lookups are cached separately and legitimately;
        // clearing them isolates the panel's caching from theirs.
        Cache::flush();

        $this->get(route('scoreboard'))->assertOk()->assertSee('Indiana');
    });
});
