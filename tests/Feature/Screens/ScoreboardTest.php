<?php

use App\Actions\FollowTeam;
use App\Actions\ReorderFollowedTeams;
use App\Models\Conference;
use App\Models\Game;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
    // Both `location` and `display_name`, because the two are shown in
    // different places: a game card names the place, a team page the full name.
    // A fixture that sets only one cannot tell them apart.
    $this->georgia = Team::factory()->create([
        'id' => 61, 'location' => 'Georgia', 'display_name' => 'Georgia Bulldogs',
    ]);
    $this->alabama = Team::factory()->create([
        'id' => 333, 'location' => 'Alabama', 'display_name' => 'Alabama Crimson Tide',
    ]);

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
        ->assertSee('Georgia')
        ->assertSee('Alabama')
        // The place, not the nickname. A card is scanned rather than read, and
        // "Bulldogs" is nine characters in front of what the reader wants.
        ->assertDontSee('Bulldogs')
        ->assertDontSee('Crimson Tide');
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
    $outsider = Team::factory()->create(['location' => 'Some Independent']);

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
        ->assertSee('Georgia')
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

    $unranked = Team::factory()->create(['location' => 'Unranked State']);

    Game::factory()->finished()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => $unranked->id, 'away_team_id' => Team::factory()->create()->id,
    ]);

    Livewire::test('scoreboard')
        ->set('week', $this->week->id)
        ->set('scope', Scope::TOP_25)
        ->assertSee('Georgia')
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

    // The has-live answer is deliberately cached 15s (every viewer's 30s
    // poll asked the EXISTS fresh); this render must see the new game.
    Cache::forget('scoreboard:has-live');

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
            // ONE offset at every width: the header's measured height, which
            // already carries the standalone status-bar inset and the section
            // strip in the areas that have one. Scores has no strip, so here
            // it resolves to what the old summed pair gave.
            ->assertSee('sticky top-[var(--chrome-offset)] z-30 -mx-4 -mt-5', escape: false);
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

    it('keeps the chrome offset off the component root, where a morph would strip it', function () {
        /*
         * The server HTML carries no `style` attribute on the component root,
         * so Livewire's morph treats an inline one as drift and removes it.
         * Writing the offset there meant picking a different week wiped it,
         * `top` fell back to 0, and the day headings stuck underneath the
         * chrome. document.documentElement is never morphed.
         */
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('document.documentElement.style.setProperty', escape: false)
            ->assertDontSee('this.$el.style.setProperty', escape: false);
    });

    it('adds the chrome\'s own sticky offset, not just its height', function () {
        // The chrome is `top-0` at base but `sm:top-14`, so its resting bottom
        // edge is height PLUS that offset. Height alone parked every day
        // heading 56px too high from sm up, behind the chrome.
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('getComputedStyle(chrome).top', escape: false);
    });

    it('stacks day headings above card contents', function () {
        /*
         * An opaque background is not enough on its own. A game card's inner
         * wrapper is `relative` with `z-index: auto`, which opens no stacking
         * context, so the team rows keep their `z-10` in the ROOT context —
         * tying with the heading and winning on tree order. Team names painted
         * over the background and it read as though there were none.
         */
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('sticky z-20 -mx-4 flex min-w-0 items-center gap-1.5 bg-white', escape: false);
    });

    it('gives day headings an opaque background', function () {
        // A translucent heading with cards sliding under it was hard to read;
        // backdrop-blur softens what is behind but does not stop it competing.
        $response = $this->get(route('scoreboard'))->assertOk();

        $response->assertSee('bg-white px-4 py-1.5 dark:bg-zinc-950', escape: false)
            ->assertDontSee('bg-white/90', escape: false);
    });
});

describe('team naming on a card', function () {
    /*
     * A card names the PLACE. The nickname belongs on a team page, where there
     * is room for it and where a reader has stopped to read rather than scan.
     */
    it('shortens a long place name rather than truncating it', function () {
        // ESPN's own shortening, which is better than anything we would invent:
        // "Florida International" is FIU to everyone who follows the sport.
        $team = Team::factory()->create([
            'location' => 'Florida International',
            'short_display_name' => 'FIU',
            'display_name' => 'Florida International Golden Panthers',
        ]);

        expect($team->placeName())->toBe('FIU');
    });

    it('keeps a place name that fits', function () {
        $team = Team::factory()->create([
            'location' => 'Ohio State',
            'short_display_name' => 'Ohio State',
            'display_name' => 'Ohio State Buckeyes',
        ]);

        expect($team->placeName())->toBe('Ohio State');
    });

    it('falls back to the place when there is no shortening to use', function () {
        // 103 of 136 FBS teams have identical location and short_display_name,
        // but nothing guarantees the column is populated.
        $team = Team::factory()->create([
            'location' => 'Somewhere Exceedingly Long',
            'short_display_name' => null,
        ]);

        expect($team->placeName())->toBe('Somewhere Exceedingly Long');
    });
});

describe('fixtures with no teams yet', function () {
    /*
     * The whole postseason is TBD-vs-TBD until December. A scope filter matches
     * on teams, so filtering these out on "does not involve an FBS team" empties
     * the bowl and playoff slates for the eleven months when knowing the date,
     * venue and bowl name is the only thing on offer.
     */
    beforeEach(function () {
        $this->tbd = Game::factory()->create([
            'season_id' => $this->season->id,
            'week_id' => $this->week->id,
            'home_team_id' => null,
            'away_team_id' => null,
            'note' => 'Boca Raton Bowl',
            'completed' => false,
            'status' => 'pre',
            'kickoff_at' => '2025-09-25 18:00:00',
        ]);
    });

    it('shows an unannounced fixture under a team scope', function () {
        Livewire::test('scoreboard')
            ->set('week', $this->week->id)
            ->set('scope', Scope::FBS)
            ->assertOk()
            ->assertSee('Boca Raton Bowl')
            ->assertSee('TBD');
    });

    it('shows it under a conference scope too', function () {
        // A fixture with no teams cannot belong to a conference, but it cannot
        // be excluded from one either — there is nothing to judge it on.
        Livewire::test('scoreboard')
            ->set('week', $this->week->id)
            ->set('scope', '8')
            ->assertOk()
            ->assertSee('Boca Raton Bowl');
    });

    it('still excludes a game whose teams are simply out of scope', function () {
        // The escape hatch is for UNANNOUNCED games, not for widening the
        // filter — a real matchup outside the scope must stay filtered out.
        $outsider = Team::factory()->create(['location' => 'Some Independent']);

        Game::factory()->finished()->create([
            'season_id' => $this->season->id,
            'week_id' => $this->week->id,
            'home_team_id' => $outsider->id,
            'away_team_id' => Team::factory()->create()->id,
        ]);

        Livewire::test('scoreboard')
            ->set('week', $this->week->id)
            ->set('scope', '8')
            ->assertDontSee('Some Independent');
    });
});

describe('horizontal overflow', function () {
    /*
     * The page body must never scroll sideways. When it does, the fixed tab bar
     * and the sticky chrome stop lining up with content that is travelling
     * horizontally underneath them, and the whole screen reads as coming apart.
     *
     * The cause is always the same shape: a flex or grid ITEM keeps its
     * automatic minimum size, which is its MIN-CONTENT width. Any `truncate`
     * inside it sets `white-space: nowrap`, whose min-content width is the
     * entire unwrapped string — so the item grows to fit the text instead of
     * clipping it, and `truncate` never gets a constrained box to work against.
     *
     * `min-w-0` on the item is what lets truncation actually happen. These
     * assertions pin it on the containers that hold long unwrappable strings.
     */
    it('lets a game card shrink below its longest caption', function () {
        // "College Football Playoff Quarterfinal at the Chick-fil-A Peach Bowl"
        // is the longest string the app renders inside a card.
        Game::factory()->finished()->create([
            'season_id' => $this->season->id,
            'week_id' => $this->week->id,
            'home_team_id' => 61,
            'away_team_id' => 333,
            'note' => 'College Football Playoff Quarterfinal at the Goodyear Cotton Bowl Classic',
        ]);

        Livewire::test('scoreboard')
            ->set('week', $this->week->id)
            ->set('scope', Scope::FBS)
            ->assertSee('flex min-w-0 flex-col rounded-lg border', escape: false);
    });
});

describe('followed teams float to the top', function () {
    beforeEach(function () {
        Queue::fake();

        $this->fan = User::factory()->create();
        app(FollowTeam::class)->handle($this->fan, Team::find(61));

        // Georgia plays Saturday; two other games sit ahead of it on Thursday,
        // so an unfloated card would be third and below a day heading.
        $this->theirs = Game::factory()->finished()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'kickoff_at' => '2025-09-27 19:30:00',
        ]);

        $this->other = Team::factory()->create(['id' => 99, 'location' => 'Elsewhere']);
        TeamSeason::create([
            'team_id' => 99, 'season_year' => 2025,
            'conference_id' => 8, 'classification' => 'FBS',
        ]);

        Game::factory()->finished()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 99, 'away_team_id' => 333,
            'kickoff_at' => '2025-09-25 19:30:00',
        ]);
    });

    it('lifts their game above the day groups', function () {
        $html = $this->actingAs($this->fan)
            ->get(route('scoreboard', ['week' => $this->week->id, 'scope' => Scope::FBS]))
            ->assertOk()
            ->getContent();

        // Position, not presence: the whole point is that it comes FIRST, ahead
        // of a Thursday game that is earlier in the week.
        expect(strpos($html, 'data-pinned="true"'))->toBeInt()
            ->and(strpos($html, 'data-pinned="true"'))->toBeLessThan(strpos($html, 'Elsewhere'));
    });

    it('shows it once, not twice', function () {
        // Floating a game moves it; it does not copy it. A card appearing both
        // pinned and in its own day group reads as a duplicate fixture.
        $html = $this->actingAs($this->fan)
            ->get(route('scoreboard', ['week' => $this->week->id, 'scope' => Scope::FBS]))
            ->getContent();

        expect(substr_count($html, 'wire:key="game-'.$this->theirs->id.'"'))->toBe(1);
    });

    it('keeps the date, which the card alone does not carry', function () {
        // Lifted out of the chronology, a card only says "7:30pm". The date has
        // to ride on the pinned heading itself — asserting it appears somewhere
        // on the page would pass off the ordinary day heading below.
        $html = $this->actingAs($this->fan)
            ->get(route('scoreboard', ['week' => $this->week->id, 'scope' => Scope::FBS]))
            ->getContent();

        // Wide enough to clear the inline star SVG that sits before the text.
        $pinnedHeading = substr($html, strpos($html, 'data-pinned="true"'), 2000);

        expect($pinnedHeading)->toContain('Georgia')
            ->and($pinnedHeading)->toContain('Saturday, Sep 27');
    });

    it('does NOT show their game when the scope excludes them', function () {
        /*
         * The rule that matters. An unranked followed team under a Top 25 filter
         * stays hidden — floating is a reordering of what the scope already
         * admitted, never a way past it.
         */
        Ranking::create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'poll' => 'ap', 'team_id' => 99, 'rank' => 1, 'record' => '5-0',
        ]);

        Cache::flush();

        $url = fn (string $scope) => route('scoreboard', ['week' => $this->week->id, 'scope' => $scope]);

        // The contrast IS the assertion. Asserting only that nothing is pinned
        // under Top 25 would pass just as well with the whole feature switched
        // off, so pin the control case in the same test.
        $underFbs = $this->actingAs($this->fan)->get($url(Scope::FBS))->getContent();

        expect($underFbs)->toContain('data-pinned="true"');

        $this->actingAs($this->fan)
            ->get($url(Scope::TOP_25))
            ->assertOk()
            // Ranked, so the slate is not simply empty.
            ->assertSee('Elsewhere')
            ->assertDontSee('data-pinned', escape: false);
    });

    it('leaves a guest scoreboard entirely alone', function () {
        $url = route('scoreboard', ['week' => $this->week->id, 'scope' => Scope::FBS]);

        // Same contrast: the signed-in case proves the marker can appear at all.
        expect($this->actingAs($this->fan)->get($url)->getContent())
            ->toContain('data-pinned="true"');

        auth()->logout();

        $this->get($url)->assertOk()->assertDontSee('data-pinned', escape: false);
    });

    it('does not print an empty state when the only game in scope is theirs', function () {
        Game::query()->whereKeyNot($this->theirs->id)->delete();

        $this->actingAs($this->fan)
            ->get(route('scoreboard', ['week' => $this->week->id, 'scope' => Scope::FBS]))
            ->assertOk()
            ->assertDontSee('Nothing on the slate');
    });
});

describe('all followed teams float', function () {
    beforeEach(function () {
        $this->vols = Team::factory()->create(['id' => 2633, 'location' => 'Tennessee', 'display_name' => 'Tennessee Volunteers']);
        $this->tide = Team::factory()->create(['id' => 334, 'location' => 'Alabama Crimson', 'display_name' => 'Alabama Crimson Tide']);
        $this->neutral = Team::factory()->create(['id' => 77, 'location' => 'Neutralville']);

        foreach ([2633, 334, 77] as $id) {
            TeamSeason::create([
                'team_id' => $id, 'season_year' => 2025,
                'conference_id' => 8, 'classification' => 'FBS',
            ]);
        }

        $this->fan = User::factory()->create();
        // Followed Alabama first, then reordered Tennessee to the top — so the
        // ordering under test is the USER'S order, not follow order.
        foreach ([$this->tide, $this->vols] as $team) {
            app(FollowTeam::class)->handle($this->fan, $team);
        }
        app(ReorderFollowedTeams::class)->handle($this->fan, [$this->vols->id, $this->tide->id]);

        $this->slate = fn () => $this->actingAs($this->fan)
            ->get(route('scoreboard', ['week' => $this->week->id, 'scope' => Scope::FBS]))
            ->getContent();
    });

    it('floats a followed team that is not the one they ranked first', function () {
        Game::factory()->finished()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 334, 'away_team_id' => 77,
            'kickoff_at' => '2025-09-27 19:30:00',
        ]);

        $html = ($this->slate)();

        expect(substr($html, strpos($html, 'data-pinned="true"'), 2000))
            ->toContain('Alabama Crimson');
    });

    it('puts the team they ranked first above the others', function () {
        Game::factory()->finished()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 334, 'away_team_id' => 77,
            'kickoff_at' => '2025-09-25 19:30:00',
        ]);

        // Tennessee kicks off LATER, so chronology would put Alabama first.
        Game::factory()->finished()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 2633, 'away_team_id' => 61,
            'kickoff_at' => '2025-09-27 19:30:00',
        ]);

        $html = ($this->slate)();

        expect(strpos($html, 'Tennessee'))->toBeLessThan(strpos($html, 'Alabama Crimson'));
    });

    it('shows a game between two followed teams once, under the higher-ranked one', function () {
        /*
         * Both teams want this game. Walking them in priority order and marking
         * each game claimed is what stops the same card rendering twice — which
         * would read as two different fixtures.
         */
        $shared = Game::factory()->finished()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 2633, 'away_team_id' => 334,
            'kickoff_at' => '2025-09-27 19:30:00',
        ]);

        $html = ($this->slate)();

        expect(substr_count($html, 'wire:key="game-'.$shared->id.'"'))->toBe(1)
            ->and(substr_count($html, 'data-pinned="true"'))->toBe(1)
            ->and(substr($html, strpos($html, 'data-pinned="true"'), 2000))->toContain('Tennessee');
    });

    it('still respects the scope for every followed team', function () {
        /*
         * BOTH sides out of scope, which is the case worth testing. Moving only
         * Alabama proves nothing: a game shows when EITHER team is in scope, so
         * their SEC opponent would keep it on the board — and floating it there
         * is correct, because the scope already admitted it.
         */
        Conference::factory()->create(['id' => 999, 'name' => 'Other', 'short_name' => 'OTH']);

        TeamSeason::whereIn('team_id', [334, 77])->update(['conference_id' => 999]);

        Game::factory()->finished()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 334, 'away_team_id' => 77,
            'kickoff_at' => '2025-09-27 19:30:00',
        ]);

        Cache::flush();

        $html = $this->actingAs($this->fan)
            ->get(route('scoreboard', ['week' => $this->week->id, 'scope' => '8']))
            ->getContent();

        expect($html)->not->toContain('Alabama Crimson');
    });
});

describe('the split opening week', function () {
    beforeEach(function () {
        // ESPN's real 2025 shape: ONE "Week 1" row holding two Saturdays,
        // 8/23 and 8/30 — the fans' Week 0 and Week 1.
        $this->openingWeek = Week::create([
            'season_id' => $this->season->id, 'number' => 1, 'name' => 'Week 1',
            'start_date' => '2025-08-23 07:00', 'end_date' => '2025-09-02 06:59',
        ]);

        $this->vanderbilt = Team::factory()->create(['location' => 'Vanderbilt', 'display_name' => 'Vanderbilt Commodores']);
        $this->kentucky = Team::factory()->create(['location' => 'Kentucky', 'display_name' => 'Kentucky Wildcats']);

        foreach ([$this->vanderbilt->id, $this->kentucky->id] as $teamId) {
            TeamSeason::create([
                'team_id' => $teamId, 'season_year' => 2025,
                'conference_id' => 8, 'classification' => 'FBS',
            ]);
        }

        Game::factory()->finished()->create([
            'season_id' => $this->season->id, 'week_id' => $this->openingWeek->id,
            'home_team_id' => 61, 'away_team_id' => 333,
            'kickoff_at' => '2025-08-23 20:00:00',
        ]);

        Game::factory()->create([
            'season_id' => $this->season->id, 'week_id' => $this->openingWeek->id,
            'home_team_id' => $this->vanderbilt->id, 'away_team_id' => $this->kentucky->id,
            'kickoff_at' => '2025-08-30 19:30:00',
        ]);
    });

    it('gives the opening week two stops, labeled the way fans count', function () {
        $entries = collect(app(CfbCalendar::class)->weekReleases(2025))
            ->where('week_id', $this->openingWeek->id);

        expect($entries)->toHaveCount(2)
            ->and($entries->pluck('label')->all())->toBe(['WEEK 0', 'WEEK 1'])
            ->and($entries->pluck('bracket')->all())->toBe(['wk0', '']);
    });

    it('shows only the selected card, though both stops share one week id', function () {
        Livewire::test('scoreboard')
            ->set('scope', Scope::FBS)
            ->call('selectWeek', $this->openingWeek->id, 'wk0')
            ->assertSee('Georgia')
            ->assertDontSee('Vanderbilt');

        Livewire::test('scoreboard')
            ->set('scope', Scope::FBS)
            ->call('selectWeek', $this->openingWeek->id, '')
            ->assertSee('Vanderbilt')
            ->assertDontSee('Georgia');
    });
});

/** A pinned Eastern kickoff on the fixture's Saturday. */
function scoreboardKick(string $time, string $date = '2025-09-27'): CarbonImmutable
{
    return CarbonImmutable::parse($date.' '.$time, config('cfb.timezone'));
}

/**
 * A home team with a unique place name, its FBS membership, and its game in
 * one pinned state. Every column an ORDER assertion reads is set here.
 */
function scoreboardGame(int $id, string $place, string $time, array $state, string $date = '2025-09-27'): Game
{
    Team::factory()->create([
        'id' => $id, 'location' => $place, 'display_name' => $place.' Club',
        'short_display_name' => $place, 'abbreviation' => mb_strtoupper(mb_substr($place, 0, 3)),
        'color' => '123456', 'alt_color' => '654321',
    ]);

    TeamSeason::create([
        'team_id' => $id, 'season_year' => 2025,
        'conference_id' => 8, 'classification' => 'FBS',
    ]);

    return Game::factory()->create([
        'season_id' => test()->season->id,
        'week_id' => test()->week->id,
        'home_team_id' => $id,
        'away_team_id' => 900,
        'kickoff_at' => scoreboardKick($time, $date),
    ] + $state);
}

describe('live games float above finals', function () {
    /*
     * Every column these tests read is pinned. `GameFactory` defaults
     * `kickoff_at` to a random four-month window and `TeamFactory` mints a
     * random `alt_color` and an `abbreviation` derived from a faker city —
     * an unpinned fixture on an ORDER assertion is a coin flip.
     *
     * Kickoffs are written in Eastern, which is the timezone the day heading
     * is formatted in. The 20:00 game is 00:00 UTC Sunday on purpose: it is
     * still Saturday night to everyone watching, and it proves the day
     * grouping survived the stratification.
     */
    beforeEach(function () {
        // 16:30 ET on that Saturday: the noon window is over, the afternoon
        // window is running, the night games have not kicked. Every state in
        // the fixtures below is the state it would really be in.
        $this->travelTo(scoreboardKick('16:30'));

        $this->opponent = Team::factory()->create([
            'id' => 900, 'location' => 'Visitorton', 'display_name' => 'Visitorton Guests',
            'short_display_name' => 'Visitorton', 'abbreviation' => 'VIS',
            'color' => '123456', 'alt_color' => '654321',
        ]);

        TeamSeason::create([
            'team_id' => 900, 'season_year' => 2025,
            'conference_id' => 8, 'classification' => 'FBS',
        ]);

        $this->live = ['status' => 'in', 'completed' => false];
        $this->halftime = ['status' => 'halftime', 'completed' => false];
        $this->upcoming = ['status' => 'pre', 'completed' => false];
        $this->final = ['status' => 'post', 'completed' => true, 'home_score' => 31, 'away_score' => 17];
    });

    it('orders one day live, then upcoming, then final', function () {
        // Created in kickoff order, which is the order the old query gave and
        // therefore the order this must NOT come back in.
        scoreboardGame(901, 'Earlyfinal', '11:00', $this->final);
        scoreboardGame(902, 'Noonfinal', '12:00', $this->final);
        scoreboardGame(903, 'Afternoonlive', '15:30', $this->live);
        scoreboardGame(904, 'Latelive', '16:00', $this->halftime);
        scoreboardGame(905, 'Nightgame', '19:00', $this->upcoming);
        scoreboardGame(906, 'Lategame', '20:00', $this->upcoming);

        Cache::forget('scoreboard:has-live');

        Livewire::test('scoreboard')
            ->set('scope', Scope::FBS)
            ->set('week', $this->week->id)
            // Live first, then what has not kicked, and the settled half
            // last. Kickoff still decides inside each band — 15:30 before
            // 16:00, 19:00 before 20:00, 11:00 before 12:00 — so this is the
            // old ordering stratified, not replaced.
            ->assertSeeInOrder([
                'Afternoonlive',
                'Latelive',
                'Nightgame',
                'Lategame',
                'Earlyfinal',
                'Noonfinal',
            ]);
    });

    it('counts halftime and end of period as live, not as a kickoff still to come', function () {
        scoreboardGame(901, 'Earlyfinal', '11:00', $this->final);
        scoreboardGame(904, 'Latelive', '16:00', ['status' => 'end-period', 'completed' => false]);
        scoreboardGame(905, 'Nightgame', '19:00', $this->upcoming);

        Cache::forget('scoreboard:has-live');

        // A game between quarters is a game somebody is watching. Ranking it
        // with the kickoffs would drop it below the night games for the
        // length of a commercial break.
        Livewire::test('scoreboard')
            ->set('scope', Scope::FBS)
            ->set('week', $this->week->id)
            ->assertSeeInOrder(['Latelive', 'Nightgame', 'Earlyfinal']);
    });

    it('stratifies inside each day heading, never across the week', function () {
        // The day headings are the screen's structure. Saturday's live game
        // floats over Saturday's final and NOT over Thursday's heading —
        // stratifying across the whole week would strand it under the wrong
        // date, which is the same mistake as losing the date off a pinned
        // followed-team group.
        scoreboardGame(901, 'Thursfinal', '19:00', $this->final, '2025-09-25');
        scoreboardGame(902, 'Earlyfinal', '11:00', $this->final);
        scoreboardGame(903, 'Afternoonlive', '15:30', $this->live);

        Cache::forget('scoreboard:has-live');

        Livewire::test('scoreboard')
            ->set('scope', Scope::FBS)
            ->set('week', $this->week->id)
            ->assertSeeInOrder([
                'Thursday, Sep 25',
                'Thursfinal',
                'Saturday, Sep 27',
                'Afternoonlive',
                'Earlyfinal',
            ]);
    });

    it('stratifies a followed team block the same way', function () {
        // The pinned block runs through the same closure, so a followed
        // team's live game floats inside their own block for the same reason
        // it does in the day groups. 900 is the away side of every game in
        // this fixture, so following them pins both.
        scoreboardGame(901, 'Earlyfinal', '11:00', $this->final);
        scoreboardGame(903, 'Afternoonlive', '15:30', $this->live);

        Queue::fake();
        $user = User::factory()->create();
        app(FollowTeam::class)->handle($user, $this->opponent);

        Cache::forget('scoreboard:has-live');

        Livewire::actingAs($user)->test('scoreboard')
            ->set('scope', Scope::FBS)
            ->set('week', $this->week->id)
            ->assertSeeInOrder(['Visitorton', 'Afternoonlive', 'Earlyfinal']);
    });
});
