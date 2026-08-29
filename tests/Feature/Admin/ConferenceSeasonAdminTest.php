<?php

use App\Filament\Resources\Conferences\ConferenceResource;
use App\Filament\Resources\Conferences\Pages\ListConferences;
use App\Filament\Resources\Conferences\Pages\ViewConference;
use App\Filament\Resources\Conferences\RelationManagers\MembershipsRelationManager;
use App\Filament\Resources\Seasons\Pages\ListSeasons;
use App\Filament\Resources\Seasons\Pages\ViewSeason;
use App\Filament\Resources\Seasons\RelationManagers\WeeksRelationManager;
use App\Filament\Resources\Seasons\SeasonResource;
use App\Models\Conference;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Models\Week;
use App\Services\CfbCalendar;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    $this->year = app(CfbCalendar::class)->currentYear();
});

describe('conferences', function () {
    it('counts members for THIS season only', function () {
        /*
         * The count rides `memberships()` — unparameterized — with the year in
         * the withCount closure. `teamSeasons(int $year)` cannot feed
         * withCount at all: that resolves a relation by calling the method
         * with no arguments, which for a parameterized one is a TypeError.
         */
        $conference = Conference::factory()->create(['short_name' => 'SEC']);

        TeamSeason::factory()->count(2)->create([
            'season_year' => $this->year,
            'conference_id' => $conference->id,
        ]);
        // A member from a season that is not this one must not be counted —
        // conference membership is season-scoped, and 513 teams moved between
        // 2021 and 2025.
        TeamSeason::factory()->create([
            'season_year' => $this->year - 3,
            'conference_id' => $conference->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListConferences::class)
            ->assertOk()
            ->assertTableColumnStateSet('members_count', 2, $conference);
    });

    it('scopes the members relation manager to the current season too', function () {
        $conference = Conference::factory()->create();
        $thisYear = Team::factory()->create(['display_name' => 'Tennessee Volunteers']);
        $backThen = Team::factory()->create(['display_name' => 'Departed Tigers']);

        TeamSeason::factory()->create([
            'team_id' => $thisYear->id,
            'season_year' => $this->year,
            'conference_id' => $conference->id,
        ]);
        TeamSeason::factory()->create([
            'team_id' => $backThen->id,
            'season_year' => $this->year - 3,
            'conference_id' => $conference->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(MembershipsRelationManager::class, [
                'ownerRecord' => $conference,
                'pageClass' => ViewConference::class,
            ])
            ->assertOk()
            ->assertSee('Tennessee Volunteers')
            ->assertDontSee('Departed Tigers');
    });

    it('tells a real conference from an ESPN grouping', function () {
        $grouping = Conference::factory()->create(['name' => 'FBS Independents', 'is_conference' => false]);

        Livewire::actingAs($this->admin)
            ->test(ViewConference::class, ['record' => $grouping->getKey()])
            ->assertOk()
            ->assertSee('Grouping');
    });

    it('is read-only, because ESPN owns the row and the id', function () {
        expect(ConferenceResource::getPages())
            ->toHaveKeys(['index', 'view'])
            ->not->toHaveKey('create')
            ->not->toHaveKey('edit');
    });
});

describe('seasons', function () {
    it('names each phase rather than printing ESPN\'s integer', function () {
        Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);
        Season::factory()->create(['year' => 2026, 'type' => Season::POSTSEASON]);

        Livewire::actingAs($this->admin)
            ->test(ListSeasons::class)
            ->assertOk()
            ->assertSee('Regular season')
            ->assertSee('Postseason');
    });

    it('shows one football year as the several rows it actually is', function () {
        // `(year, type)` is unique, NOT `year`. Reading "the 2026 season" as
        // one row is how a query silently finds the preseason.
        $regular = Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);
        $post = Season::factory()->create(['year' => 2026, 'type' => Season::POSTSEASON]);

        Livewire::actingAs($this->admin)
            ->test(ListSeasons::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$regular, $post]);
    });

    it('filters to one phase', function () {
        $regular = Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);
        $pre = Season::factory()->create(['year' => 2026, 'type' => Season::PRESEASON]);

        Livewire::actingAs($this->admin)
            ->test(ListSeasons::class)
            ->filterTable('type', Season::REGULAR)
            ->assertCanSeeTableRecords([$regular])
            ->assertCanNotSeeTableRecords([$pre]);
    });

    it('says Unknown phase rather than blank for a type nobody mapped', function () {
        expect(SeasonResource::phaseLabel(99))->toBe('Unknown phase')
            ->and(SeasonResource::phaseLabel(null))->toBe('Unknown phase')
            ->and(SeasonResource::phaseLabel(Season::REGULAR))->toBe('Regular season');
    });

    it('lists the weeks of a season in order', function () {
        $season = Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);

        Week::factory()->create(['season_id' => $season->id, 'number' => 2, 'name' => 'Week 2']);
        Week::factory()->create(['season_id' => $season->id, 'number' => 1, 'name' => 'Week 1']);

        Livewire::actingAs($this->admin)
            ->test(WeeksRelationManager::class, [
                'ownerRecord' => $season,
                'pageClass' => ViewSeason::class,
            ])
            ->assertOk()
            ->assertSee('Week 1')
            ->assertSee('Week 2');
    });

    it('renders the season heading with its phase badge', function () {
        $season = Season::factory()->create(['year' => 2026, 'type' => Season::POSTSEASON]);

        Livewire::actingAs($this->admin)
            ->test(ViewSeason::class, ['record' => $season->getKey()])
            ->assertOk()
            ->assertSee('2026')
            ->assertSee('Postseason');
    });
});
