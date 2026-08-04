<?php

use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);

    // An upcoming season with no poll — the case that emptied the rail.
    Season::factory()->create([
        'year' => 2026, 'type' => Season::REGULAR,
        'start_date' => '2026-08-22', 'end_date' => '2026-12-13',
    ]);

    $this->week15 = Week::create(['season_id' => $this->season->id, 'number' => 15, 'name' => 'Week 15', 'start_date' => '2025-12-01', 'end_date' => '2025-12-07']);
    $this->week16 = Week::create(['season_id' => $this->season->id, 'number' => 16, 'name' => 'Week 16', 'start_date' => '2025-12-08', 'end_date' => '2025-12-14']);

    $this->indiana = Team::factory()->create(['id' => 84, 'slug' => 'indiana-hoosiers', 'display_name' => 'Indiana Hoosiers']);
    $this->miami = Team::factory()->create(['id' => 2390, 'slug' => 'miami-hurricanes', 'display_name' => 'Miami Hurricanes']);

    foreach ([['ap', 1, 66], ['usa', 1, 62]] as [$poll, $rank, $votes]) {
        Ranking::create([
            'season_id' => $this->season->id, 'week_id' => $this->week16->id,
            'poll' => $poll, 'team_id' => $this->indiana->id, 'rank' => $rank,
            'previous_rank' => 1, 'points' => 1650, 'first_place_votes' => $votes, 'record' => '16-0',
        ]);
    }

    Ranking::create([
        'season_id' => $this->season->id, 'week_id' => $this->week16->id,
        'poll' => 'ap', 'team_id' => $this->miami->id, 'rank' => 2,
        'previous_rank' => 10, 'points' => 1584, 'record' => '13-3',
    ]);
});

it('renders rankings for guests', function () {
    $this->get(route('rankings'))->assertOk();
});

it('defaults to the latest season and week that actually have a poll', function () {
    // 2026 exists and is chronologically later, but has no poll at all.
    Livewire::test('rankings')
        ->assertSet('year', 2025)
        ->assertSet('week', 16)
        ->assertSee('Indiana Hoosiers');
});

it('switches poll and re-resolves the week', function () {
    // Polls do not all run the same weeks — the CFP poll only starts in
    // November — so changing poll has to re-resolve, not keep a stale week.
    Livewire::test('rankings')
        ->set('poll', 'usa')
        ->assertSet('week', 16)
        ->assertSee('62 first')
        ->assertDontSee('66 first');
});

it('lets a user pick an earlier week', function () {
    Ranking::create([
        'season_id' => $this->season->id, 'week_id' => $this->week15->id,
        'poll' => 'ap', 'team_id' => $this->miami->id, 'rank' => 1, 'record' => '12-3',
    ]);

    Livewire::test('rankings')
        ->set('week', 15)
        ->assertSee('Miami Hurricanes')
        ->assertDontSee('Indiana Hoosiers');
});

it('shows movement relative to the previous poll', function () {
    // Miami went 10 -> 2.
    Livewire::test('rankings')->assertSee('▲8');
});

it('links every ranked team', function () {
    Livewire::test('rankings')
        ->assertSee(route('team', $this->indiana), escape: false)
        ->assertSee(route('team', $this->miami), escape: false);
});

it('only offers polls that have data', function () {
    Livewire::test('rankings')
        ->assertSee('AP Top 25')
        ->assertSee('Coaches')
        // No CFP rows exist, so it must not be offered.
        ->assertDontSee('>CFP<', escape: false);
});

it('shows an empty state rather than erroring for a season with no poll', function () {
    Livewire::test('rankings')
        ->set('year', 2026)
        ->assertOk()
        ->assertSee('No poll published');
});
