<?php

use App\Models\Conference;
use App\Models\Game;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Scope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);

    $this->week = Week::create([
        'season_id' => $this->season->id,
        'number' => 5,
        'name' => 'Week 5',
        'start_date' => '2025-09-23',
        'end_date' => '2025-09-29',
    ]);

    $conference = Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC']);
    $this->georgia = Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs']);
    $this->alabama = Team::factory()->create(['id' => 333, 'display_name' => 'Alabama Crimson Tide']);

    foreach ([61, 333] as $teamId) {
        TeamSeason::create([
            'team_id' => $teamId,
            'season_year' => 2025,
            'conference_id' => $conference->id,
            'classification' => 'FBS',
        ]);
    }
});

it('renders the scoreboard for guests', function () {
    $this->get(route('scoreboard'))->assertOk();
});

it('shows games for the selected week', function () {
    Game::factory()->finished(31, 17)->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    Livewire::test('scoreboard')
        ->set('scope', Scope::FBS)
        ->set('week', $this->week->id)
        ->assertSee('Georgia Bulldogs')
        ->assertSee('Alabama Crimson Tide');
});

it('never calls ESPN while rendering', function () {
    // The single most important assertion on this screen. v3 called ESPN inside
    // render(), so a live game cost one upstream request per viewer per poll.
    Http::fake();

    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    Livewire::test('scoreboard')->set('scope', Scope::FBS)->set('week', $this->week->id)->assertOk();

    Http::assertNothingSent();
});

it('scopes games through season-scoped conference membership', function () {
    $outsider = Team::factory()->create(['display_name' => 'Some Independent']);

    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => $outsider->id,
        'away_team_id' => Team::factory()->create()->id,
    ]);

    Livewire::test('scoreboard')
        ->set('week', $this->week->id)
        ->set('scope', '8')
        ->assertSee('Georgia Bulldogs')
        ->assertDontSee('Some Independent');
});

it('defaults to Top 25 when a poll exists, and lists FBS second', function () {
    // Opening on every game in the country is not a useful first screen — but
    // only once there is a poll to open on. This fixture has none until the
    // ranking below, which is the summer state the default has to survive.
    expect(Livewire::test('scoreboard')->get('scope'))->toBe(Scope::FBS);

    Ranking::create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'poll' => 'ap', 'team_id' => 61, 'rank' => 1, 'record' => '5-0',
    ]);

    Cache::flush();

    expect(Livewire::test('scoreboard')->get('scope'))->toBe(Scope::TOP_25);

    $options = Scope::options(2025);

    expect($options[0]['value'])->toBe(Scope::TOP_25)
        ->and($options[1]['value'])->toBe(Scope::FBS);
});

it('labels conferences with short_name, never the slug abbreviation', function () {
    // `conferences.abbreviation` holds an ESPN URL slug — `sec`, `big10`,
    // `midam` — so rendering it would put lowercase slugs across four screens.
    $labels = collect(Scope::options(2025))->pluck('label');

    expect($labels)->toContain('SEC')
        ->and($labels)->not->toContain('sec');
});

it('restricts Top 25 to ranked teams', function () {
    Ranking::create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'poll' => 'ap', 'team_id' => 61, 'rank' => 1, 'record' => '5-0',
    ]);

    Game::factory()->finished()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
    ]);

    $unranked = Team::factory()->create(['display_name' => 'Unranked State']);

    Game::factory()->finished()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => $unranked->id, 'away_team_id' => Team::factory()->create()->id,
    ]);

    Livewire::test('scoreboard')
        ->set('week', $this->week->id)
        ->set('scope', Scope::TOP_25)
        ->assertSee('Georgia Bulldogs')
        ->assertDontSee('Unranked State');
});

it('has no season selector', function () {
    // Scores is a "what is on now" screen. Comparing years belongs on
    // Standings, Rankings, Stats and Leaders, where it is the point.
    expect(Livewire::test('scoreboard')->instance())
        ->not->toHaveProperty('year');
});

it('only polls while a game is actually in progress', function () {
    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    Livewire::test('scoreboard')->set('scope', Scope::FBS)->set('week', $this->week->id)
        ->assertDontSee('wire:poll', escape: false);

    Game::factory()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
        'status' => 'in',
        'completed' => false,
    ]);

    Livewire::test('scoreboard')->set('scope', Scope::FBS)->set('week', $this->week->id)
        ->assertSee('wire:poll', escape: false);
});

it('shows an empty state rather than erroring when a week has no games', function () {
    Livewire::test('scoreboard')
        ->set('scope', Scope::FBS)
        ->set('week', $this->week->id)
        ->assertOk()
        ->assertSee('Nothing on the slate');
});

describe('postseason in the week scroller', function () {
    beforeEach(function () {
        $this->post = Season::factory()->create([
            'year' => 2025, 'type' => Season::POSTSEASON,
            'start_date' => '2025-12-13', 'end_date' => '2026-01-21',
        ]);

        $this->bowlWeek = Week::create([
            'season_id' => $this->post->id, 'number' => 1, 'name' => 'Bowls',
            'start_date' => '2025-12-13', 'end_date' => '2026-01-21',
        ]);

        $this->bowl = Game::factory()->finished()->create([
            'season_id' => $this->post->id, 'week_id' => $this->bowlWeek->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'kickoff_at' => '2025-12-27 17:00:00',
            'note' => 'Union Home Mortgage Gasparilla Bowl',
        ]);

        $this->title = Game::factory()->finished()->create([
            'season_id' => $this->post->id, 'week_id' => $this->bowlWeek->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'kickoff_at' => '2026-01-20 19:30:00',
            'note' => 'College Football Playoff National Championship Presented by AT&T',
        ]);
    });

    it('splits one ESPN postseason week into BOWLS and CFP', function () {
        /*
         * ESPN publishes the whole postseason as ONE week called "Bowls" —
         * verified live, `types/3/weeks` returns a single item covering Dec 13
         * to Jan 21 and holding both the 35 ordinary bowls and the 11 playoff
         * games. Leaving it undivided buries the playoff inside the bowl slate.
         */
        $entries = collect(app(CfbCalendar::class)->weekReleases(2025))
            ->where('week_id', $this->bowlWeek->id);

        expect($entries)->toHaveCount(2)
            ->and($entries->pluck('bracket')->all())->toBe(['bowls', 'cfp'])
            ->and($entries->pluck('label')->all())->toBe(['BOWLS', 'CFP']);
    });

    it('dates each half from its own games, not the shared week', function () {
        // The week spans both halves, so using it would put "DEC 13" on the CFP
        // pill when the playoff starts a week later.
        $entries = collect(app(CfbCalendar::class)->weekReleases(2025))
            ->where('week_id', $this->bowlWeek->id)->keyBy('bracket');

        expect($entries['bowls']['range'])->toContain('DEC 27')
            ->and($entries['cfp']['range'])->toContain('JAN 20');
    });

    it('shows only the playoff when CFP is selected', function () {
        Livewire::test('scoreboard')
            ->set('scope', Scope::FBS)
            ->call('selectWeek', $this->bowlWeek->id, 'cfp')
            ->assertSee('National Championship')
            ->assertDontSee('Gasparilla');
    });

    it('shows only the bowls when BOWLS is selected', function () {
        Livewire::test('scoreboard')
            ->set('scope', Scope::FBS)
            ->call('selectWeek', $this->bowlWeek->id, 'bowls')
            ->assertSee('Gasparilla')
            ->assertDontSee('National Championship');
    });

    it('moves both dimensions together', function () {
        // The two pills share a week id, so setting the id alone would leave a
        // stale bracket and show the wrong half.
        $component = Livewire::test('scoreboard')
            ->call('selectWeek', $this->bowlWeek->id, 'cfp')
            ->call('selectWeek', $this->week->id, '');

        expect($component->get('week'))->toBe($this->week->id)
            ->and($component->get('bracket'))->toBe('');
    });

    it('names a bowl on its card instead of "A at B"', function () {
        // games.name only ever holds "A at B", so every bowl rendered as an
        // ordinary fixture until the event note was stored.
        Livewire::test('scoreboard')
            ->set('scope', Scope::FBS)
            ->call('selectWeek', $this->bowlWeek->id, 'bowls')
            ->assertSee('Union Home Mortgage Gasparilla Bowl');
    });
});

describe('scope availability', function () {
    it('disables Top 25 and defaults to FBS when the season has no poll', function () {
        /*
         * The normal state all summer — the preseason AP poll does not land
         * until August. Previously the filter offered Top 25, resolved it to
         * every FBS team, and displayed "Top 25" over 138 teams' worth of
         * games. Greying it out says the filter exists and is not available
         * yet.
         */
        $unpolled = Season::factory()->create([
            'year' => 2027, 'type' => Season::REGULAR,
            'start_date' => '2027-08-28', 'end_date' => '2027-12-12',
        ]);

        TeamSeason::create(['team_id' => 61, 'season_year' => 2027, 'classification' => 'FBS']);

        expect(Scope::hasRankings(2027))->toBeFalse()
            ->and(Scope::defaultFor(2027))->toBe(Scope::FBS);

        $top25 = collect(Scope::options(2027))->firstWhere('value', Scope::TOP_25);

        expect($top25['disabled'])->toBeTrue();
    });

    it('enables Top 25 once a poll exists', function () {
        Ranking::create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'poll' => 'ap', 'team_id' => 61, 'rank' => 1, 'record' => '5-0',
        ]);

        expect(Scope::hasRankings(2025))->toBeTrue()
            ->and(Scope::defaultFor(2025))->toBe(Scope::TOP_25)
            ->and(collect(Scope::options(2025))->firstWhere('value', Scope::TOP_25)['disabled'])
            ->toBeFalse();
    });

    it('renders a disabled Top 25 as non-selectable, with a reason', function () {
        // Not a menu item: those are focusable and selectable, so a disabled one
        // would still land under the keyboard.
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('aria-disabled="true"', escape: false)
            ->assertSee('No poll yet');
    });
});

describe('sticky chrome', function () {
    it('sticks the heading and week strip together', function () {
        // They travel as one block so the reader always knows which week they
        // are scrolling through.
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('sticky top-0 z-20', escape: false)
            // Clears the layout header, which only exists from sm upward.
            ->assertSee('sm:top-14', escape: false);
    });

    beforeEach(function () {
        // Day headings only exist when there are games to head.
        Game::factory()->finished()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 61, 'away_team_id' => 333,
        ]);
    });

    it('offsets day headings below that block by its measured height', function () {
        /*
         * Measured at runtime rather than hardcoded: the strip's height depends
         * on the font, and the title wraps at narrow widths. A guessed constant
         * leaves either a gap or an overlap.
         */
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('--scores-chrome', escape: false)
            ->assertSee('top: var(--scores-chrome', escape: false);
    });

    it('gives day headings an opaque background', function () {
        // A translucent heading with cards sliding under it was hard to read;
        // backdrop-blur softens what is behind but does not stop it competing.
        $response = $this->get(route('scoreboard'))->assertOk();

        $response->assertSee('bg-white px-4 py-1.5 dark:bg-zinc-950', escape: false)
            ->assertDontSee('bg-white/90', escape: false);
    });
});
