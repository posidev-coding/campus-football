<?php

use App\Enums\StandingSource;
use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Game;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use Livewire\Livewire;

/*
 * v3 never shipped this screen: the route was commented out and the component
 * rendered the word "Standings" twice, because the data underneath could not be
 * made correct.
 */

beforeEach(function () {
    Conference::factory()->create(['id' => 8, 'name' => 'SEC', 'is_conference' => true]);
    ConferenceSeason::create(['conference_id' => 8, 'season_year' => 2025, 'classification' => 'FBS']);

    Team::factory()->create(['id' => 61, 'location' => 'Georgia', 'display_name' => 'Georgia Bulldogs']);
    Team::factory()->create(['id' => 333, 'location' => 'Alabama', 'display_name' => 'Alabama Crimson Tide']);

    Standing::create([
        'season_year' => 2025, 'conference_id' => 8, 'team_id' => 61,
        'source' => StandingSource::Espn,
        'conf_wins' => 7, 'conf_losses' => 1, 'overall_wins' => 12, 'overall_losses' => 1,
        'conf_win_pct' => 0.875, 'win_pct' => 0.923, 'streak' => 'W9',
    ]);

    Standing::create([
        'season_year' => 2025, 'conference_id' => 8, 'team_id' => 333,
        'source' => StandingSource::Espn,
        'conf_wins' => 4, 'conf_losses' => 4, 'overall_wins' => 8, 'overall_losses' => 4,
        'conf_win_pct' => 0.5, 'win_pct' => 0.667, 'streak' => 'L1',
    ]);
});

it('renders standings for guests', function () {
    $this->get(route('standings'))->assertOk();
});

it('shows conference and overall records', function () {
    Livewire::test('standings')
        ->set('year', 2025)
        ->assertSee('Georgia')
        ->assertSee('7-1')
        ->assertSee('12-1')
        ->assertSee('W9');
});

it('orders by conference record', function () {
    Livewire::test('standings')
        ->set('year', 2025)
        ->assertSeeInOrder(['Georgia', 'Alabama']);
});

it('puts a team that has won above a team that has not kicked off', function () {
    /*
     * Opening weekend, when most of a conference is still 0-0. ESPN seeds only
     * the teams that have played and files everyone else under seed 0, so this
     * screen spent week 1 showing every team that had not kicked off above the
     * ones that had won. Auburn is that 1-0 team; Vanderbilt has not played.
     */
    Team::factory()->create(['id' => 2, 'location' => 'Auburn', 'display_name' => 'Auburn Tigers']);
    Team::factory()->create(['id' => 238, 'location' => 'Vanderbilt', 'display_name' => 'Vanderbilt Commodores']);

    Standing::create([
        'season_year' => 2019, 'conference_id' => 8, 'team_id' => 238,
        'source' => StandingSource::Espn,
        'win_pct' => 0.0, 'conf_win_pct' => 0.0, 'playoff_seed' => 0,
    ]);

    Standing::create([
        'season_year' => 2019, 'conference_id' => 8, 'team_id' => 2,
        'source' => StandingSource::Espn,
        'overall_wins' => 1, 'win_pct' => 1.0, 'conf_win_pct' => 0.0,
        'playoff_seed' => 1, 'point_differential' => 24,
    ]);

    ConferenceSeason::create(['conference_id' => 8, 'season_year' => 2019, 'classification' => 'FBS']);

    Livewire::test('standings')
        ->set('year', 2019)
        ->assertSeeInOrder(['Auburn', 'Vanderbilt']);
});

it('shows the authoritative ESPN source, not the computed cross-check', function () {
    // The computed row deliberately disagrees. It exists for the reconciler and
    // the admin panel; it must never reach a public screen.
    Standing::create([
        'season_year' => 2025, 'conference_id' => 8, 'team_id' => 61,
        'source' => StandingSource::Computed,
        'conf_wins' => 2, 'conf_losses' => 6, 'overall_wins' => 3, 'overall_losses' => 9,
    ]);

    Livewire::test('standings')
        ->set('year', 2025)
        ->assertSee('7-1')
        ->assertDontSee('2-6');
});

it('shows an empty state for a season with no standings', function () {
    Livewire::test('standings')
        ->set('year', 2019)
        ->assertOk()
        ->assertSee('No standings yet');
});

describe('the default season', function () {
    /*
     * The August shape: 2025 is fully played, 2026 is scheduled and current.
     * ESPN publishes the upcoming season's standings months ahead as 0-0
     * rows — the screen should open there, exactly as ESPN's own site does,
     * and fill in for real the moment week 1 completes.
     */
    beforeEach(function () {
        $this->travelTo('2026-08-06');

        $regular2025 = Season::factory()->create([
            'year' => 2025, 'type' => Season::REGULAR,
            'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
        ]);

        Season::factory()->create([
            'year' => 2026, 'type' => Season::PRESEASON,
            'start_date' => '2026-02-01', 'end_date' => '2026-08-22',
        ]);

        $regular2026 = Season::factory()->create([
            'year' => 2026, 'type' => Season::REGULAR,
            'start_date' => '2026-08-22', 'end_date' => '2026-12-12',
        ]);

        // Pinned kickoffs: a random factory date can land slate-eligible.
        Game::factory()->finished()->create([
            'season_id' => $regular2025->id, 'kickoff_at' => '2025-10-04 19:30:00',
        ]);
        Game::factory()->create([
            'season_id' => $regular2026->id, 'kickoff_at' => '2026-09-05 19:30:00', 'completed' => false,
        ]);
    });

    it('opens on the season being played once ESPN publishes standings for it', function () {
        Standing::create([
            'season_year' => 2026, 'conference_id' => 8, 'team_id' => 61,
            'source' => StandingSource::Espn,
            'conf_wins' => 0, 'conf_losses' => 0, 'overall_wins' => 0, 'overall_losses' => 0,
        ]);

        Livewire::test('standings')->assertSet('year', 2026);
    });

    it('falls back to the latest played season while the upcoming one has no rows', function () {
        Livewire::test('standings')->assertSet('year', 2025);
    });

    it('lets a bookmarked year win over the default', function () {
        Standing::create([
            'season_year' => 2026, 'conference_id' => 8, 'team_id' => 61,
            'source' => StandingSource::Espn,
            'conf_wins' => 0, 'conf_losses' => 0, 'overall_wins' => 0, 'overall_losses' => 0,
        ]);

        Livewire::withQueryParams(['year' => 2025])
            ->test('standings')
            ->assertSet('year', 2025);
    });
});

describe('the scope filter', function () {
    beforeEach(function () {
        // An FCS conference with standings, to prove the divisions separate.
        Conference::factory()->create(['id' => 30, 'name' => 'Southern Conference', 'short_name' => 'SoCon', 'is_conference' => true]);
        ConferenceSeason::create(['conference_id' => 30, 'season_year' => 2025, 'classification' => 'FCS']);

        Team::factory()->create(['id' => 2000, 'location' => 'Furman', 'display_name' => 'Furman Paladins']);

        Standing::create([
            'season_year' => 2025, 'conference_id' => 30, 'team_id' => 2000,
            'source' => StandingSource::Espn,
            'conf_wins' => 6, 'conf_losses' => 2, 'overall_wins' => 9, 'overall_losses' => 3,
        ]);
    });

    it('separates the divisions as sub-tabs, FBS first', function () {
        // Two different LISTS, not a narrowing of one — most readers never
        // leave FBS, so the split is a tab, and the menu within a division
        // says "All FBS" rather than a bare acronym beside conference names.
        Livewire::test('standings')
            ->set('year', 2025)
            ->assertSee('Georgia')
            ->assertDontSee('Furman')
            ->assertSee('All FBS')
            ->set('scope', 'fcs')
            ->assertSee('Furman')
            ->assertDontSee('Georgia')
            ->assertSee('All FCS');
    });

    it('narrows to one conference by id, and its division tab stays lit', function () {
        $screen = Livewire::test('standings')
            ->set('year', 2025)
            ->set('scope', '30')
            ->assertSee('Furman')
            ->assertDontSee('Georgia');

        // The FCS tab is current even though the scope is a conference id —
        // the id belongs to a division too.
        expect($screen->get('division'))->toBe('fcs');
    });

    it('falls back to FBS on a value it does not recognise', function () {
        // A pre-rename bookmark carries ?classification=FCS&conference=8 —
        // neither reaches the property — or a hand-edited ?scope=nonsense.
        // `#[Url]` hydrates without firing the update hook, so mount() has to
        // normalise too; this exercises that path through the querystring.
        Livewire::withQueryParams(['scope' => 'nonsense'])
            ->test('standings')
            ->set('year', 2025)
            ->assertSet('scope', 'fbs')
            ->assertSee('Georgia');
    });
});
