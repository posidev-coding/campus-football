<?php

use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use App\Models\Conference;
use App\Models\Game;
use App\Models\Position;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamLeader;
use App\Models\TeamSeason;
use App\Models\TeamSeasonStat;
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
        ->set('tab', 'stats')
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
        ->set('tab', 'stats')
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
        ->set('tab', 'stats')
        ->assertOk()
        ->assertSee('No leaders yet');
});

describe('the tabs', function () {
    it('opens on the schedule', function () {
        // What someone opening a team page came to see. Overview is gone —
        // its only content was the leaders, which now live under Stats.
        $game = Game::factory()->create([
            'season_id' => Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR])->id,
            'home_team_id' => 61, 'away_team_id' => null,
            'kickoff_at' => '2025-09-06 19:30:00',
        ]);

        Livewire::test('team', ['team' => $this->team])
            ->assertSet('tab', 'schedule')
            ->assertSeeInOrder(['Schedule', 'Roster', 'Stats', 'News'])
            ->assertSee(route('game', $game), escape: false);
    });

    it('floats an unlabeled season select to the right of the tabs', function () {
        // One row: tabs left, season right. The visible "Season" label is
        // gone — four-digit years speak for themselves and the label was the
        // widest thing on the row — so the accessible name carries it.
        Livewire::test('team', ['team' => $this->team])
            ->assertSee('aria-label="Season"', escape: false)
            ->assertDontSee('>Season<', escape: false)
            ->set('year', 2023)
            ->assertSet('year', 2023);
    });
});

describe('the stats tab', function () {
    beforeEach(function () {
        foreach ([
            'passingYards' => '2691',
            'totalTackles' => '96',
            'receptions' => '54',
        ] as $category => $value) {
            TeamLeader::create([
                'team_id' => 61, 'season_year' => 2025, 'category' => $category,
                'athlete_id' => $this->qb->id, 'rank' => 1, 'display_value' => $value,
            ]);
        }

        // `stats` is KEYED by ESPN's stat name, not a list.
        TeamSeasonStat::create([
            'team_id' => 61, 'season_year' => 2025, 'season_type' => 2, 'category' => 'defensive',
            'stats' => ['sacks' => ['display' => '34', 'value' => 34, 'rank' => 12, 'label' => 'Sacks']],
        ]);
        TeamSeasonStat::create([
            'team_id' => 61, 'season_year' => 2025, 'season_type' => 2, 'category' => 'scoring',
            'stats' => ['points' => ['display' => '412', 'value' => 412, 'rank' => 8, 'label' => 'Points']],
        ]);
    });

    it('groups individual leaders by position type', function () {
        Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)
            ->set('tab', 'stats')
            ->assertSeeInOrder(['Passing', 'Receiving', 'Defense']);
    });

    it('toggles to team stats, offense before defense', function () {
        // ESPN publishes these flat and alphabetically-ish, so `defensive`
        // came first and the page opened on tackles rather than points.
        Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)
            ->set('tab', 'stats')
            ->set('statsView', 'team')
            ->assertSeeInOrder(['Offense', 'Scoring', 'Defense'])
            ->assertSee('412');
    });

    it('renders the scope toggle as underlined tabs, not a second pill group', function () {
        /*
         * The scope filter lives INSIDE the tab the strip above selected, so
         * rendering both as segmented pills made a child look like a sibling.
         * Exactly one pill group survives on the page — the tabs.
         */
        $html = Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)
            ->set('tab', 'stats')
            ->html();

        expect(substr_count($html, 'ui-radio-group'))->toBe(2)   // one open + one close tag
            ->and($html)->toContain('Players')
            ->toContain('border-zinc-900 text-zinc-900 dark:border-zinc-100');
    });

    it('keeps leaders and team stats out of each other\'s view', function () {
        $leaders = Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)->set('tab', 'stats');

        $leaders->assertSee('2691')->assertDontSee('412');

        $leaders->set('statsView', 'team')
            ->assertSee('412')
            ->assertDontSee('2691');
    });
});

describe('the branded hero', function () {
    it('sets all three palette variables on the hero', function () {
        // Surface, far end and text together — the far end must come from PHP
        // because CSS cannot know which way to move it.
        $this->team->update(['color' => '154733', 'alt_color' => '000000']);

        $palette = $this->team->fresh()->palette();

        Livewire::test('team', ['team' => $this->team])
            ->assertSee('--team-accent: '.$palette->surface, escape: false)
            ->assertSee('--team-accent-far: '.$palette->far, escape: false)
            ->assertSee('--team-accent-contrast: '.$palette->text, escape: false);
    });

    it('never seats the logo on the accent surface', function () {
        // The logo rides a neutral puck — white in light mode, near-black in
        // dark — because a one-color mark in the team's own color vanishes
        // into an accent background.
        $this->team->update(['color' => '154733', 'alt_color' => '000000']);

        Livewire::test('team', ['team' => $this->team])
            ->assertSee('team-gradient', escape: false)
            // White puck in light mode; in dark the header is neutral, so the
            // puck disappears entirely rather than going dark itself.
            ->assertSee('bg-white shadow-md ring-1 ring-black/10 dark:bg-transparent', escape: false)
            ->assertSee('--team-accent-contrast: #ffffff', escape: false);
    });

    it('draws the alt color as a keyline when the team has one', function () {
        // Via the team-keyline utility and its variable, not an inline
        // border — an inline style cannot be switched off in dark mode.
        $this->team->update(['alt_color' => 'BA0C2F']);

        Livewire::test('team', ['team' => $this->team])
            ->assertSee('--team-keyline: #BA0C2F', escape: false)
            ->assertSee('team-keyline', escape: false);
    });

    it('goes neutral in dark mode: no puck chrome, no branded button', function () {
        // The classes carry the dark behavior; the CSS utilities un-brand the
        // surface itself under `.dark`.
        Livewire::test('team', ['team' => $this->team])
            ->assertSee('dark:bg-transparent dark:shadow-none dark:ring-0', escape: false)
            ->assertSee('team-invert', escape: false);
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
            // Lighter, but NOT italic — italics read as an aside here.
            ->toContain('text-base font-light leading-tight')
            ->not->toContain('font-light italic')
            ->toContain('Mountaineers');

        // The mascot line renders after the place.
        expect(strpos($html, 'App State</span>'))->toBeLessThan(strpos($html, 'Mountaineers</span>'));
    });

    it('keeps the follow button in the hero, drawing the hero\'s own colors', function () {
        /*
         * On the accent, but styled FROM it via the team-invert utility — the
         * fill is the hero's text color and the label is the accent, the one
         * pairing the header already proved readable. A utility rather than
         * an inline style so dark mode can neutralize it.
         */
        Livewire::test('team', ['team' => $this->team])
            ->assertSee('team-invert', escape: false)
            ->assertSee('Follow');
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
