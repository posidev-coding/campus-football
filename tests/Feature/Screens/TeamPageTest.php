<?php

use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use App\Models\Conference;
use App\Models\Game;
use App\Models\Position;
use App\Models\Recruit;
use App\Models\RecruitSchool;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamLeader;
use App\Models\TeamSeason;
use App\Models\TeamSeasonStat;
use App\Models\Week;
use App\Support\RecruitingClasses;
use Livewire\Livewire;

/*
 * Everything the whole file leans on is PINNED, including the columns the
 * factory would otherwise randomize. Two of them reach the rendered page:
 *
 *   alt_color     drives TeamPalette's ladder, and a random secondary crosses
 *                 the 7.0 rung often enough to swap --team-accent-contrast
 *                 between white and the secondary from run to run — so the
 *                 hero renders a different set of hex strings each time, on
 *                 every screen in this file
 *   abbreviation  TeamFactory derives it from a random city, not from the
 *                 pinned location, so the fixture disagreed with itself
 *
 * Neither is what any test here is about, and an unpinned value in a shared
 * fixture is only ever one `assertDontSee` away from a coin flip.
 */
beforeEach(function () {
    Conference::factory()->create([
        'id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC',
        // ESPN's URL slug, not an abbreviation — see conferences.abbreviation.
        'abbreviation' => 'sec',
    ]);

    $this->team = Team::factory()->create([
        'id' => 61,
        'slug' => 'georgia-bulldogs',
        'location' => 'Georgia',
        // The mascot lives in `name`; `nickname` is ESPN's short location
        // alias, which is why the fixture sets both realistically.
        'name' => 'Bulldogs',
        'nickname' => 'Georgia',
        'display_name' => 'Georgia Bulldogs',
        'abbreviation' => 'UGA',
        'color' => '154733',
        'alt_color' => '000000',
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

    // Stats opens on Team now, so the Players half has to be asked for.
    Livewire::test('team', ['team' => $this->team])
        ->set('year', 2025)
        ->set('tab', 'stats')
        ->set('statsView', 'players')
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
        ->set('statsView', 'players')
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

describe('the roster squad tabs', function () {
    beforeEach(function () {
        Position::create(['id' => 30, 'name' => 'Linebacker', 'abbreviation' => 'LB']);
        Position::create(['id' => 22, 'name' => 'Place Kicker', 'abbreviation' => 'PK']);

        foreach ([[30, 'defense', 'Dax Backer'], [22, 'special_teams', 'Kip Kicker']] as [$pid, $group, $name]) {
            $athlete = Athlete::create(['id' => $pid * 100, 'display_name' => $name]);

            AthleteTeamSeason::create([
                'athlete_id' => $athlete->id, 'team_id' => 61, 'season_year' => 2025,
                'position_id' => $pid, 'position_group' => $group,
            ]);
        }
    });

    it('opens on the whole squad, not on offense', function () {
        // A roster tab that opened filtered would hide two thirds of the team
        // from someone who came to look at the team.
        Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)->set('tab', 'roster')
            ->assertSet('rosterGroup', '')
            ->assertSee('Gunner Stockton')
            ->assertSee('Dax Backer')
            ->assertSee('Kip Kicker');
    });

    it('offers the squads in ESPN order and filters to one', function () {
        Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)->set('tab', 'roster')
            ->assertSeeInOrder(['>All<', '>Offense<', '>Defense<', '>Special Teams<'], escape: false)
            ->set('rosterGroup', 'defense')
            ->assertSee('Dax Backer')
            ->assertDontSee('Gunner Stockton')
            ->assertDontSee('Kip Kicker');
    });

    it('hides the strip on a roster with no position groups', function () {
        /*
         * 119 teams' most recent roster predates the current one and is derived
         * from box scores, which carry a team and a jersey and NO position
         * group. A one-tab strip is chrome, not a filter.
         */
        $bare = Team::factory()->create([
            'id' => 99, 'slug' => 'bare-college', 'location' => 'Bare', 'display_name' => 'Bare College',
            'abbreviation' => 'BAR', 'color' => '1d4ed8', 'alt_color' => 'ffffff',
        ]);
        $walkOn = Athlete::create(['id' => 9001, 'display_name' => 'Walk On']);

        AthleteTeamSeason::create([
            'athlete_id' => $walkOn->id, 'team_id' => 99, 'season_year' => 2025,
        ]);

        Livewire::test('team', ['team' => $bare])
            ->set('year', 2025)->set('tab', 'roster')
            ->assertSee('Walk On')
            ->assertDontSee('aria-label="Squad"', escape: false);
    });
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
        ->set('statsView', 'players')
        ->assertOk()
        ->assertSee('No leaders yet');
});

describe('the tabs', function () {
    it('opens on the schedule', function () {
        // What someone opening a team page came to see. Overview is gone —
        // its only content was the leaders, which now live under Stats.
        $game = Game::factory()->create([
            'id' => 401_752_601,
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
            ->set('statsView', 'players')
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

    it('renders the scope toggle as an underlined plate, not a second gutter', function () {
        /*
         * The scope filter lives INSIDE the tab the gutter above selected, so
         * rendering both in the gutter language made a child look like a
         * sibling. Exactly one gutter track survives on the page — the
         * level-1 tabs — and the Team/Players toggle is underlined.
         */
        $html = Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)
            ->set('tab', 'stats')
            ->html();

        expect(substr_count($html, 'bg-zinc-800/5'))->toBe(1)
            ->and($html)->toContain('Players')
            ->toContain('border-zinc-900 text-zinc-900 dark:border-zinc-100');
    });

    it('opens the Stats tab on Team, with Players a tap away', function () {
        /*
         * Team leads — "how good is this team" before "who on it is good" —
         * matching the League Stats screen, where the leftmost tab is also the
         * default. The two halves must not bleed into each other: 412 is a
         * team stat and 2691 an individual one, so each view shows exactly one.
         */
        $stats = Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)->set('tab', 'stats');

        $stats->assertSet('statsView', 'team')
            ->assertSee('412')
            ->assertDontSee('2691');

        $stats->set('statsView', 'players')
            ->assertSee('2691')
            ->assertDontSee('412');
    });

    it('puts Team left of Players', function () {
        // Order is the array's, and it has to match the League screen's or the
        // same control reads two ways in one app.
        Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)
            ->set('tab', 'stats')
            ->assertSeeInOrder(['>Team<', '>Players<'], escape: false);
    });
});

describe('the recruiting tab', function () {
    beforeEach(function () {
        $this->rival = Team::factory()->create([
            'id' => 77, 'slug' => 'rival-college', 'location' => 'Rival', 'display_name' => 'Rival College',
            'abbreviation' => 'RIV', 'color' => '7c2d12', 'alt_color' => 'ffffff',
        ]);

        // Georgia's class.
        $this->signee = Recruit::create([
            'espn_id' => 1, 'recruiting_class' => 2025, 'display_name' => 'Blue Chip',
            'grade' => 92, 'national_rank' => 5, 'committed_team_id' => 61,
            'high_school' => 'Some High School',
        ]);

        // One who went elsewhere but Georgia was in on.
        $this->missed = Recruit::create([
            'espn_id' => 2, 'recruiting_class' => 2025, 'display_name' => 'The One That Got Away',
            'grade' => 95, 'national_rank' => 1, 'committed_team_id' => 77,
        ]);

        RecruitSchool::create(['recruit_id' => $this->missed->id, 'espn_team_id' => 61, 'team_id' => 61, 'status' => 'Undecided']);
    });

    it('lists this team\'s class, not another team\'s', function () {
        Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)->set('tab', 'recruiting')
            ->assertSee('Blue Chip')
            ->assertSee('Some High School');
    });

    it('shows the class rank, and it agrees with the League screen', function () {
        /*
         * Both read App\Support\RecruitingClasses, so a team cannot be 9th on
         * one screen and 12th on the other.
         */
        $summary = Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)->set('tab', 'recruiting')
            ->get('classSummary');

        $league = collect(RecruitingClasses::forClass(2025))
            ->search(fn (array $row) => $row['team']['id'] === 61);

        expect($summary['rank'])->toBe($league + 1)
            ->and($summary['signees'])->toBe(1);
    });

    it('shows who the team recruited and lost', function () {
        // Only possible because the sync stores the whole interest list rather
        // than the commitment alone.
        Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)->set('tab', 'recruiting')
            ->assertSee('Also recruited')
            ->assertSee('The One That Got Away');
    });

    it('follows the page\'s season rather than carrying its own control', function () {
        Livewire::test('team', ['team' => $this->team])
            ->set('year', 2024)->set('tab', 'recruiting')
            ->assertSee('No commitments')
            ->assertDontSee('Blue Chip');
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
        Conference::factory()->create([
            'id' => 9, 'name' => 'Atlantic Coast Conference', 'short_name' => 'ACC', 'abbreviation' => 'acc',
        ]);

        // Five conference-mates with better records put Georgia 6th. Pinned
        // ids and names: a factory-minted team draws a random city, nickname
        // and two hex colors, none of which this is about.
        foreach (range(1, 5) as $i) {
            $rival = Team::factory()->create([
                'id' => 200 + $i,
                'slug' => "conference-mate-{$i}",
                'location' => "Mate {$i}",
                'name' => 'Rivals',
                'display_name' => "Mate {$i} Rivals",
                'abbreviation' => 'MT'.$i,
                'color' => '3f3f46',
                'alt_color' => 'ffffff',
            ]);
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

describe('the season it opens on', function () {
    it('opens on the season being played or approached, not the last one finished', function () {
        /*
         * The August trap. From February to kickoff the upcoming season is
         * fully scheduled but unplayed, so resultsYear() still points at last
         * season — and a team page defaulting to it showed a finished
         * schedule while the real one sat one row away in the database.
         */
        $upcoming = Season::factory()->create([
            'year' => 2026, 'type' => Season::REGULAR,
            'start_date' => '2026-08-29', 'end_date' => '2026-12-12',
        ]);

        Game::factory()->create([
            'id' => 401_800_001,
            'season_id' => $upcoming->id,
            // Explicitly TBD rather than left to the factory, which would mint
            // a whole random opponent — a team with a random name and colors
            // that then renders into this very page.
            'home_team_id' => 61, 'away_team_id' => null,
            'kickoff_at' => '2026-08-29 19:00:00',
            'completed' => false,
        ]);

        expect(Livewire::test('team', ['team' => $this->team])->get('year'))->toBe(2026);
    });

    it('offers the upcoming season in the selector at all', function () {
        // latestYear() fed the select the same wrong value, so the current
        // year was not merely un-defaulted — it was unreachable.
        $upcoming = Season::factory()->create([
            'year' => 2026, 'type' => Season::REGULAR,
            'start_date' => '2026-08-29', 'end_date' => '2026-12-12',
        ]);
        Game::factory()->create([
            'id' => 401_800_002,
            'season_id' => $upcoming->id, 'home_team_id' => 61, 'away_team_id' => null,
            'kickoff_at' => '2026-08-29 19:00:00', 'completed' => false,
        ]);

        // The year menu keys each option, so the year being OFFERED (not just
        // mentioned somewhere) is what this matches.
        Livewire::test('team', ['team' => $this->team])
            ->assertSee('wire:key="season-2026"', escape: false);
    });

    it('shows last season\'s stats, labelled, before the new one kicks off', function () {
        // An empty Stats tab for a season that has not started is a worse
        // answer than last season's numbers under a label — the same call the
        // roster already makes.
        TeamSeasonStat::create([
            'team_id' => 61, 'season_year' => 2025, 'season_type' => 2, 'category' => 'scoring',
            'stats' => ['points' => ['display' => '430', 'value' => 430, 'rank' => 8, 'label' => 'Points']],
        ]);

        $upcoming = Season::factory()->create([
            'year' => 2026, 'type' => Season::REGULAR,
            'start_date' => '2026-08-29', 'end_date' => '2026-12-12',
        ]);
        Game::factory()->create([
            'id' => 401_800_003,
            'season_id' => $upcoming->id, 'home_team_id' => 61, 'away_team_id' => null,
            'kickoff_at' => '2026-08-29 19:00:00', 'completed' => false,
        ]);

        Livewire::test('team', ['team' => $this->team])
            ->set('tab', 'stats')
            ->set('statsView', 'team')
            // escape: false — literal template text, so the apostrophe is raw
            // in the DOM rather than the &#039; assertSee would look for.
            ->assertSee("2026 hasn't kicked off yet, so these are 2025 numbers", escape: false)
            ->assertSee('430');
    });
});

describe('schedule dates', function () {
    it('dates an upcoming game on a team schedule', function () {
        $upcoming = Season::factory()->create([
            'year' => 2026, 'type' => Season::REGULAR,
            'start_date' => '2026-08-29', 'end_date' => '2026-12-12',
        ]);

        Game::factory()->create([
            'id' => 401_800_004,
            'season_id' => $upcoming->id,
            'home_team_id' => 61, 'away_team_id' => null,
            // 00:30 UTC is still the 5th in ET — the date must be read in the
            // app's timezone, like every other kickoff on the card.
            'kickoff_at' => '2026-09-06 00:30:00',
            'completed' => false,
        ]);

        Livewire::test('team', ['team' => $this->team])
            ->set('year', 2026)
            ->assertSee('9/5');
    });

    it('leaves finished games undated — they say Final instead', function () {
        $played = Season::factory()->create([
            'year' => 2025, 'type' => Season::REGULAR,
            'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
        ]);

        Game::factory()->finished()->create([
            'id' => 401_800_005,
            'season_id' => $played->id,
            'home_team_id' => 61, 'away_team_id' => null,
            'kickoff_at' => '2025-09-06 19:30:00',
        ]);

        Livewire::test('team', ['team' => $this->team])
            ->set('year', 2025)
            ->assertSee('Final')
            ->assertDontSee('9/6');
    });

    it('leaves the scoreboard undated, where day headings already say so', function () {
        // The prop is opt-in precisely so surfaces that group by day do not
        // repeat the date on every card.
        $season = Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);
        $week = Week::create([
            'season_id' => $season->id, 'number' => 2, 'name' => 'Week 2',
            'start_date' => '2026-09-01', 'end_date' => '2026-09-07',
        ]);
        Game::factory()->create([
            'id' => 401_800_006,
            'season_id' => $season->id, 'week_id' => $week->id,
            'home_team_id' => 61, 'away_team_id' => null,
            'kickoff_at' => '2026-09-06 00:30:00', 'completed' => false,
        ]);

        Livewire::test('scoreboard')
            ->set('scope', 'fbs')
            ->set('week', $week->id)
            ->assertDontSee('>9/5<', escape: false);
    });
});
