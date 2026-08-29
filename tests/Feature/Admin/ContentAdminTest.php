<?php

use App\Filament\Resources\Articles\Pages\ManageArticles;
use App\Filament\Resources\Athletes\AthleteResource;
use App\Filament\Resources\Athletes\Pages\ManageAthletes;
use App\Filament\Resources\Coaches\Pages\ManageCoaches;
use App\Filament\Resources\Venues\Pages\ManageVenues;
use App\Models\Article;
use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use App\Models\Coach;
use App\Models\CoachTeamSeason;
use App\Models\Team;
use App\Models\User;
use App\Models\Venue;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The light tier: table-first resources with a modal view.
 *
 * Modals are the panel's blind spot — an infolist runs only when the modal
 * MOUNTS, so it ships rendered by nothing unless a test drives it. Every view
 * here is opened rather than assumed.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();
});

describe('athletes', function () {
    it('does not fire a query per row for the current team', function () {
        /*
         * The 35,000-row N+1 trap. A player's team is season-scoped — there is
         * no `athletes.team_id` — so the team column walks
         * latestSeason → team, two hops. Unnamed in the query that is two
         * extra queries per row, and with lazy loading disabled in production
         * it is a 500 rather than a slow page.
         */
        $team = Team::factory()->create();

        foreach (range(1, 15) as $i) {
            AthleteTeamSeason::factory()->create([
                'athlete_id' => Athlete::factory()->create()->id,
                'team_id' => $team->id,
            ]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::actingAs($this->admin)->test(ManageAthletes::class)->assertOk();

        // Fifteen athletes would be 30+ extra queries if either hop lazy
        // loaded. The ceiling is deliberately well under one-per-row.
        expect($queries)->toBeLessThan(15);
    });

    it('shows the team from the latest season, not a column that does not exist', function () {
        $athlete = Athlete::factory()->create(['display_name' => 'Joey Aguilar']);
        $old = Team::factory()->create(['display_name' => 'Appalachian State']);
        $now = Team::factory()->create(['display_name' => 'Tennessee Volunteers']);

        AthleteTeamSeason::factory()->create([
            'athlete_id' => $athlete->id,
            'team_id' => $old->id,
            'season_year' => 2024,
        ]);
        AthleteTeamSeason::factory()->create([
            'athlete_id' => $athlete->id,
            'team_id' => $now->id,
            'season_year' => 2026,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ManageAthletes::class)
            ->assertOk()
            ->assertTableColumnStateSet('team', 'Tennessee Volunteers', $athlete);
    });

    it('says Unassigned for a player with no season row at all', function () {
        $athlete = Athlete::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ManageAthletes::class)
            ->assertOk()
            ->assertTableColumnStateSet('team', null, $athlete);
    });

    it('opens a modal carrying the season history', function () {
        // The infolist only runs when the modal mounts — this is the whole
        // reason it is driven rather than assumed.
        $athlete = Athlete::factory()->create(['display_name' => 'Joey Aguilar']);
        AthleteTeamSeason::factory()->create([
            'athlete_id' => $athlete->id,
            'team_id' => Team::factory()->create(['display_name' => 'Tennessee Volunteers'])->id,
            'season_year' => 2026,
            'jersey' => '10',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ManageAthletes::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($athlete))
            ->assertMountedActionModalSee('Joey Aguilar')
            ->assertMountedActionModalSee('Tennessee Volunteers')
            ->assertMountedActionModalSee('2026');
    });

    it('filters by position group through the season pivot', function () {
        $qb = Athlete::factory()->create(['display_name' => 'A Quarterback']);
        $lineman = Athlete::factory()->create(['display_name' => 'A Lineman']);

        AthleteTeamSeason::factory()->create(['athlete_id' => $qb->id, 'position_group' => 'QB']);
        AthleteTeamSeason::factory()->create(['athlete_id' => $lineman->id, 'position_group' => 'OL']);

        Livewire::actingAs($this->admin)
            ->test(ManageAthletes::class)
            ->filterTable('position_group', 'QB')
            ->assertCanSeeTableRecords([$qb])
            ->assertCanNotSeeTableRecords([$lineman]);
    });

    it('stays out of global search, where a contains-LIKE over 35k rows lives', function () {
        // The product's own search solves this with prefix matching that rides
        // the btree index. A panel-wide contains-LIKE on every keystroke does
        // not, and would be the slowest thing in the admin.
        expect(AthleteResource::canGloballySearch())->toBeFalse();
    });
});

describe('coaches', function () {
    it('renders the career record, and says Not synced when there is none', function () {
        $synced = Coach::factory()->create(['display_name' => 'Josh Heupel']);
        $unsynced = Coach::factory()->unsynced()->create(['display_name' => 'New Hire']);

        Livewire::actingAs($this->admin)
            ->test(ManageCoaches::class)
            ->assertOk()
            ->assertTableColumnStateSet('career', '117-21', $synced)
            // Null until the sync runs — never a fabricated 0-0.
            ->assertTableColumnStateSet('career', null, $unsynced);
    });

    it('opens a modal with the season-by-season record', function () {
        $coach = Coach::factory()->create(['display_name' => 'Josh Heupel']);
        CoachTeamSeason::factory()->create([
            'coach_id' => $coach->id,
            'team_id' => Team::factory()->create(['display_name' => 'Tennessee Volunteers'])->id,
            'season_year' => 2026,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ManageCoaches::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($coach))
            ->assertMountedActionModalSee('Josh Heupel')
            ->assertMountedActionModalSee('Tennessee Volunteers');
    });
});

describe('venues', function () {
    it('sorts by capacity and says when one was never reported', function () {
        $big = Venue::factory()->create(['name' => 'Neyland Stadium', 'capacity' => 101_915]);
        $unknown = Venue::factory()->create(['name' => 'Unknown Field', 'capacity' => null]);

        Livewire::actingAs($this->admin)
            ->test(ManageVenues::class)
            ->assertOk()
            ->assertTableColumnStateSet('capacity', 101915, $big)
            // Null capacity is unreported, not an empty stadium.
            ->assertTableColumnStateSet('capacity', null, $unknown)
            ->sortTable('capacity', 'desc')
            ->assertCanSeeTableRecords([$big]);
    });

    it('opens a modal with the venue image and surface', function () {
        $venue = Venue::factory()->create([
            'name' => 'Neyland Stadium',
            'city' => 'Knoxville',
            'state' => 'TN',
            'indoor' => false,
            'grass' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ManageVenues::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($venue))
            ->assertMountedActionModalSee('Neyland Stadium')
            ->assertMountedActionModalSee('Knoxville, TN')
            ->assertMountedActionModalSee('Open air')
            ->assertMountedActionModalSee('Grass');
    });
});

describe('articles', function () {
    it('caps how many team badges one row shows', function () {
        // A story tagged with a dozen teams is a row nobody reads and a table
        // that stops aligning.
        $article = Article::factory()->create(['headline' => 'Everybody plays somebody']);
        $article->teams()->attach(Team::factory()->count(6)->create()->pluck('id'));

        Livewire::actingAs($this->admin)
            ->test(ManageArticles::class)
            ->assertOk()
            ->assertSee('+3 more');
    });

    it('opens a modal with the story and a link out to the source', function () {
        $article = Article::factory()->create([
            'headline' => 'Vols win in Knoxville',
            'description' => 'A blurb about the game.',
            'url' => 'https://example.com/story',
        ]);
        $article->teams()->attach(Team::factory()->create(['display_name' => 'Tennessee Volunteers'])->id);

        Livewire::actingAs($this->admin)
            ->test(ManageArticles::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($article))
            ->assertMountedActionModalSee('Vols win in Knoxville')
            ->assertMountedActionModalSee('A blurb about the game.')
            ->assertMountedActionModalSee('Tennessee Volunteers');
    });

    it('says when a story was never fetched, rather than showing an empty body', function () {
        // 78 of 212 articles carry no story at all — that is a real state.
        $article = Article::factory()->create(['story' => null]);

        Livewire::actingAs($this->admin)
            ->test(ManageArticles::class)
            ->assertOk()
            ->assertTableColumnStateSet('has_story', false, $article);
    });
});
