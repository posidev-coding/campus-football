<?php

use App\Enums\StandingSource;
use App\Models\Conference;
use App\Models\ConferenceSeason;
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

    Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs']);
    Team::factory()->create(['id' => 333, 'display_name' => 'Alabama Crimson Tide']);

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
        ->assertSee('Georgia Bulldogs')
        ->assertSee('7-1')
        ->assertSee('12-1')
        ->assertSee('W9');
});

it('orders by conference record', function () {
    Livewire::test('standings')
        ->set('year', 2025)
        ->assertSeeInOrder(['Georgia Bulldogs', 'Alabama Crimson Tide']);
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
