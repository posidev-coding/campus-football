<?php

use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Pages\ViewTeam;
use App\Filament\Resources\Teams\RelationManagers\FollowersRelationManager;
use App\Filament\Resources\Teams\RelationManagers\RankingsRelationManager;
use App\Filament\Resources\Teams\RelationManagers\StandingsRelationManager;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Teams\Widgets\TeamStats;
use App\Models\Conference;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Models\Week;
use App\Services\CfbCalendar;
use Livewire\Livewire;

/*
 * Team Branding, absorbed into a full Team resource.
 *
 * The branding half is still pinned by TeamBrandingTest; this file covers what
 * the absorption ADDED — season-scoped conference, the KPI widget, and the
 * relation managers.
 *
 * Conference membership is the thing to get right here: there is no
 * `teams.conference_id`, because Oregon is Pac-12 in 2021 and Big Ten in 2025.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    $this->year = app(CfbCalendar::class)->currentYear();
});

describe('the absorption', function () {
    it('replaced the single manage page with list, view and edit', function () {
        expect(TeamResource::getPages())
            ->toHaveKeys(['index', 'view', 'edit'])
            // ESPN owns `teams.id` and it does not increment, so a hand-made
            // team has no id the sync would ever match.
            ->not->toHaveKey('create');
    });

    it('lives under College Football now, not Configuration', function () {
        expect(TeamResource::getNavigationGroup())->toBe('College Football')
            ->and(TeamResource::getNavigationLabel())->toBe('Teams');
    });
});

describe('the season-scoped conference', function () {
    it('shows the conference for THIS season, not whichever row exists', function () {
        // The one mistake that broke standings across three versions.
        $team = Team::factory()->create(['display_name' => 'Oregon Ducks']);
        $oldConference = Conference::factory()->create(['short_name' => 'Pac-12']);
        $nowConference = Conference::factory()->create(['short_name' => 'Big Ten']);

        TeamSeason::factory()->create([
            'team_id' => $team->id,
            'season_year' => $this->year - 4,
            'conference_id' => $oldConference->id,
        ]);
        TeamSeason::factory()->create([
            'team_id' => $team->id,
            'season_year' => $this->year,
            'conference_id' => $nowConference->id,
        ]);

        // Asserted on the COLUMN STATE, not on the page HTML: the conference
        // filter's own option list legitimately contains every conference
        // name, so an assertDontSee over the markup would be testing the
        // dropdown rather than the row.
        Livewire::actingAs($this->admin)
            ->test(ListTeams::class)
            ->assertOk()
            ->assertTableColumnStateSet('conference', 'Big Ten', $team);
    });

    it('says so plainly for a team with no membership row this season', function () {
        // Independent, or simply not synced — either way it is not a blank.
        Team::factory()->create(['display_name' => 'Notre Dame']);

        Livewire::actingAs($this->admin)
            ->test(ListTeams::class)
            ->assertOk()
            ->assertTableColumnStateSet('conference', null, Team::query()->sole());
    });

    it('filters by conference through the season-scoped pivot', function () {
        $conference = Conference::factory()->create(['short_name' => 'SEC']);
        $inIt = Team::factory()->create(['display_name' => 'Tennessee Volunteers']);
        $notInIt = Team::factory()->create(['display_name' => 'Memphis Tigers']);

        TeamSeason::factory()->create([
            'team_id' => $inIt->id,
            'season_year' => $this->year,
            'conference_id' => $conference->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListTeams::class)
            ->filterTable('conference', $conference->id)
            ->assertCanSeeTableRecords([$inIt])
            ->assertCanNotSeeTableRecords([$notInIt]);
    });
});

describe('the KPI widget', function () {
    it('reports followers with a favorites sublabel', function () {
        $team = Team::factory()->create();
        $team->followers()->attach(User::factory()->create()->id, ['position' => 1]);
        $team->followers()->attach(User::factory()->create()->id, ['position' => 3]);

        Livewire::actingAs($this->admin)
            ->test(TeamStats::class, ['record' => $team])
            ->assertOk()
            ->assertSee('1 have it as their favorite');
    });

    it('says Unranked rather than inventing a rank number', function () {
        // Unranked is not 26 and not 0 — it is no answer, and a number
        // invented here would sort and read as a real one.
        $team = Team::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(TeamStats::class, ['record' => $team])
            ->assertOk()
            ->assertSee('Unranked');
    });

    it('says Not synced rather than 0-0 when standings have not arrived', function () {
        // v3 defaulted standings to zero on a lookup miss and overwrote 9-1
        // teams with 0-0. A display default is the same lie, one layer up.
        $team = Team::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(TeamStats::class, ['record' => $team])
            ->assertOk()
            ->assertSee('Not synced')
            ->assertDontSee('0-0');
    });

    it('reads the real record and rank when they exist', function () {
        $team = Team::factory()->create();
        $season = Season::factory()->create(['year' => $this->year, 'type' => Season::REGULAR]);

        Standing::factory()->create([
            'team_id' => $team->id,
            'season_year' => $this->year,
            'overall_wins' => 9,
            'overall_losses' => 1,
        ]);

        Ranking::factory()->create([
            'team_id' => $team->id,
            'week_id' => Week::factory()->create(['season_id' => $season->id])->id,
            'rank' => 4,
        ]);

        Livewire::actingAs($this->admin)
            ->test(TeamStats::class, ['record' => $team])
            ->assertOk()
            ->assertSee('9-1')
            ->assertSee('#4');
    });
});

describe('the relation managers', function () {
    it('shows followers and marks the ones who call it their favorite', function () {
        $team = Team::factory()->create();
        $team->followers()->attach(
            User::factory()->create(['first_name' => 'Peyton', 'last_name' => 'Manning'])->id,
            ['position' => 1],
        );

        Livewire::actingAs($this->admin)
            ->test(FollowersRelationManager::class, [
                'ownerRecord' => $team,
                'pageClass' => ViewTeam::class,
            ])
            ->assertOk()
            ->assertSee('Peyton Manning')
            ->assertSee('★ Favorite');
    });

    it('badges which source a standings row came from', function () {
        // ESPN's standings and our computed ones can disagree, and
        // `diverged_at` records that rather than silently resolving it.
        $team = Team::factory()->create();
        Standing::factory()->create(['team_id' => $team->id, 'season_year' => $this->year]);

        Livewire::actingAs($this->admin)
            ->test(StandingsRelationManager::class, [
                'ownerRecord' => $team,
                'pageClass' => ViewTeam::class,
            ])
            ->assertOk()
            ->assertSee('ESPN')
            ->assertSee('Agrees');
    });

    it('says a team was never in the previous poll rather than showing a rank', function () {
        // Null previous_rank means they were not in that poll at all, which
        // is different from being ranked last in it.
        $team = Team::factory()->create();
        $season = Season::factory()->create(['year' => $this->year, 'type' => Season::REGULAR]);

        Ranking::factory()->create([
            'team_id' => $team->id,
            'week_id' => Week::factory()->create(['season_id' => $season->id])->id,
            'rank' => 12,
            'previous_rank' => null,
        ]);

        Livewire::actingAs($this->admin)
            ->test(RankingsRelationManager::class, [
                'ownerRecord' => $team,
                'pageClass' => ViewTeam::class,
            ])
            ->assertOk()
            ->assertSee('#12')
            ->assertSee('Unranked');
    });

    it('has no games relation manager, because games is not a relation', function () {
        /*
         * `Team::games()` returns a Builder over a UNION of home and away —
         * home and away are separate denormalized columns on `games`, which is
         * what keeps the scoreboard join-free — so it is not an Eloquent
         * relation and a RelationManager cannot take it.
         */
        expect(TeamResource::getRelations())
            ->not->toContain(FollowersRelationManager::class.'Games');

        expect(collect(TeamResource::getRelations())
            ->filter(fn (string $class): bool => str_contains($class, 'Games'))
            ->all())->toBe([]);
    });
});

describe('the record view', function () {
    it('renders the heading with the season\'s conference and the resolved palette', function () {
        $conference = Conference::factory()->create(['short_name' => 'SEC']);
        $team = Team::factory()->create([
            'slug' => 'tennessee-volunteers',
            'display_name' => 'Tennessee Volunteers',
            'location' => 'Tennessee',
            'abbreviation' => 'TENN',
            'color' => 'ff8200',
        ]);

        TeamSeason::factory()->create([
            'team_id' => $team->id,
            'season_year' => $this->year,
            'conference_id' => $conference->id,
            'classification' => 'fbs',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ViewTeam::class, ['record' => $team->getRouteKey()])
            ->assertOk()
            ->assertSee('Tennessee Volunteers')
            ->assertSee('SEC')
            ->assertSee('FBS')
            // Computed by TeamPalette, never assumed — applying a color is
            // not the same as it being readable.
            ->assertSee('White on');
    });
});
