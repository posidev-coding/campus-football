<?php

use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use App\Models\Conference;
use App\Models\Position;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamLeader;
use App\Models\TeamSeason;
use Livewire\Livewire;

beforeEach(function () {
    Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC']);

    $this->team = Team::factory()->create([
        'id' => 61,
        'slug' => 'georgia-bulldogs',
        'location' => 'Georgia',
        // The mascot lives in `name`; `nickname` is ESPN's short location
        // alias, which is why the fixture sets both realistically.
        'name' => 'Bulldogs',
        'nickname' => 'Georgia',
        'display_name' => 'Georgia Bulldogs',
        'color' => '154733',
    ]);

    TeamSeason::create([
        'team_id' => 61,
        'season_year' => 2025,
        'conference_id' => 8,
        'classification' => 'FBS',
    ]);

    Position::create(['id' => 8, 'name' => 'Quarterback', 'abbreviation' => 'QB']);

    $this->qb = Athlete::create([
        'id' => 4685578,
        'display_name' => 'Gunner Stockton',
        'display_height' => "6' 1\"",
        'display_weight' => '215 lbs',
        'birth_city' => 'Tiger',
        'birth_state' => 'GA',
    ]);

    AthleteTeamSeason::create([
        'athlete_id' => $this->qb->id,
        'team_id' => 61,
        'season_year' => 2025,
        'jersey' => '14',
        'position_id' => 8,
        'position_group' => 'offense',
        'experience_class' => 'Junior',
    ]);
});

it('renders a team page for guests', function () {
    $this->get(route('team', $this->team))->assertOk();
});

it('resolves a team by slug', function () {
    // The hero writes the identity as two lines now — place over mascot — so
    // the full display name never appears as one string.
    $this->get('/teams/georgia-bulldogs')
        ->assertOk()
        ->assertSeeInOrder(['Georgia', 'Bulldogs']);
});

it('shows season leaders with their stat line', function () {
    TeamLeader::create([
        'team_id' => 61,
        'season_year' => 2025,
        'category' => 'passingLeader',
        'athlete_id' => $this->qb->id,
        'rank' => 1,
        'value' => 2691,
        'display_value' => '251/355, 2691 YDS, 23 TD, 5 INT',
    ]);

    Livewire::test('team', ['team' => $this->team])
        ->set('year', 2025)
        ->assertSee('Passing')
        ->assertSee('Gunner Stockton')
        ->assertSee('2691 YDS');
});

it('orders leaders by the published category order, not insertion order', function () {
    // Inserted deliberately out of order.
    foreach (['totalTackles', 'passingLeader'] as $i => $category) {
        TeamLeader::create([
            'team_id' => 61, 'season_year' => 2025, 'category' => $category,
            'athlete_id' => $this->qb->id, 'rank' => 1, 'display_value' => "value-{$i}",
        ]);
    }

    Livewire::test('team', ['team' => $this->team])
        ->set('year', 2025)
        ->assertSeeInOrder(['Passing', 'Tackles']);
});

it('groups the roster by position group', function () {
    Livewire::test('team', ['team' => $this->team])
        ->set('year', 2025)
        ->set('tab', 'roster')
        ->assertSee('Offense')
        ->assertSee('Gunner Stockton')
        ->assertSee('Tiger, GA');
});

it('says which season the roster belongs to when it is not the one selected', function () {
    // ESPN publishes only the current roster, so asking for an older season
    // shows the roster it does have, labelled.
    Livewire::test('team', ['team' => $this->team])
        ->set('year', 2023)
        ->set('tab', 'roster')
        ->assertSee('only the current roster');
});

it('shows empty states rather than erroring for a season with no data', function () {
    Livewire::test('team', ['team' => $this->team])
        ->set('year', 2019)
        ->assertOk()
        ->assertSee('No leaders yet');
});

describe('the branded hero', function () {
    it('computes the text color from the accent instead of assuming white', function () {
        // White text on a light accent is the same failure as an orange logo
        // on an orange surface. Maize must get near-black text.
        $maize = Team::factory()->create(['slug' => 'michigan-wolverines', 'color' => 'FFCB05']);
        $navy = Team::factory()->create(['slug' => 'navy-team', 'color' => '002244']);

        expect($maize->accentContrast())->toBe('#18181b')
            ->and($navy->accentContrast())->toBe('#ffffff')
            // Tennessee orange sits at YIQ 152 — the dark side, correctly:
            // white on #FF8200 is about 2.4:1.
            ->and(Team::factory()->make(['color' => 'FF8200'])->accentContrast())->toBe('#18181b')
            ->and(Team::factory()->make(['color' => null])->accentContrast())->toBeNull()
            ->and(Team::factory()->make(['color' => 'xyzzy!'])->accentContrast())->toBeNull();
    });

    it('never seats the logo on the accent surface', function () {
        // The logo rides a neutral puck — white in light mode, near-black in
        // dark — because a one-color mark in the team's own color vanishes
        // into an accent background.
        Livewire::test('team', ['team' => $this->team])
            ->assertSee('team-gradient', escape: false)
            ->assertSee('bg-white shadow-md ring-1 ring-black/10 dark:bg-zinc-950', escape: false)
            ->assertSee('--team-accent-contrast: #ffffff', escape: false);
    });

    it('draws the alt color as a keyline when the team has one', function () {
        $this->team->update(['alt_color' => 'BA0C2F']);

        Livewire::test('team', ['team' => $this->team])
            ->assertSee('border-bottom: 3px solid #BA0C2F', escape: false);
    });
});

describe('the hero identity', function () {
    it('splits the place and the mascot onto two untruncated lines', function () {
        // "App State" over "Mountaineers" — and the mascot comes from the
        // `name` column, because `teams.nickname` is NOT the nickname: ESPN
        // uses it for a short location alias ("App State", "Georgia").
        $this->team->update(['location' => 'App State', 'name' => 'Mountaineers', 'nickname' => 'App State']);

        $html = Livewire::test('team', ['team' => $this->team])->html();

        expect($html)->toContain('App State')
            ->toContain('font-light italic')
            ->toContain('Mountaineers');

        // The mascot line renders in the lighter italic, after the place.
        expect(strpos($html, 'App State</span>'))->toBeLessThan(strpos($html, 'Mountaineers</span>'));
    });

    it('moves the follow button below the hero and labels the season filter', function () {
        $this->team->update(['name' => 'Bulldogs', 'location' => 'Georgia']);

        // Reading order is the regression: identity in the hero, then the
        // labelled season filter, then Follow — which now sits on the page
        // surface where Flux's variants keep their contrast, instead of on
        // 136 different accent colors.
        Livewire::test('team', ['team' => $this->team])
            ->assertSeeInOrder(['Bulldogs', 'Season', 'Follow']);
    });
});

describe('the hero KPI line', function () {
    it('pairs the record with the conference position, dot-separated', function () {
        // "8-4 (4-4) · 6th in SEC" — the position phrase IS the conference
        // link, so the conference page stays one tap away.
        Conference::factory()->create(['id' => 9, 'name' => 'Atlantic Coast Conference', 'short_name' => 'ACC']);

        // Five conference-mates with better records put Georgia 6th.
        foreach (range(1, 5) as $i) {
            $rival = Team::factory()->create();
            TeamSeason::create(['team_id' => $rival->id, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);
            Standing::create([
                'season_year' => 2025, 'conference_id' => 8, 'team_id' => $rival->id, 'source' => 'espn',
                'overall_wins' => 10, 'overall_losses' => 2, 'overall_ties' => 0,
                'conf_wins' => 7, 'conf_losses' => 1, 'conf_ties' => 0,
            ]);
        }

        Standing::create([
            'season_year' => 2025, 'conference_id' => 8, 'team_id' => 61, 'source' => 'espn',
            'overall_wins' => 8, 'overall_losses' => 4, 'overall_ties' => 0,
            'conf_wins' => 4, 'conf_losses' => 4, 'conf_ties' => 0,
        ]);

        Livewire::test('team', ['team' => $this->team])
            ->assertSeeInOrder(['8-4 (4-4)', '6th in SEC'])
            ->assertSee(route('conference', ['conference' => 8, 'year' => 2025]), escape: false)
            /*
             * Livewire brackets @if blocks with `<!--[if BLOCK]-->` comment
             * markers, and they ride along INSIDE a slot's string — echoing
             * the slot escaped once printed them around this very phrase as
             * VISIBLE text. The markers are fine as real comments; what must
             * never appear is their HTML-escaped form, which is what renders
             * on screen. assertSee alone matches straight through the junk.
             */
            ->assertDontSee('&lt;!--[if', escape: false);
    });

    it('falls back to the bare conference name when no standing exists yet', function () {
        Livewire::test('team', ['team' => $this->team])
            ->set('year', 2019)
            ->assertOk()
            ->assertDontSee(' in SEC');
    });
});
