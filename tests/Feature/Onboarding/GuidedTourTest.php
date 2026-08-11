<?php

use App\Enums\ContentRating;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Support\Voice;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

/**
 * The guided coach-mark tour. Positioning is client-side geometry no feature
 * test can hold, so these pin the layer a test can: when the component
 * mounts, what it stamps, and that every spotlight target it will hunt for
 * actually exists in the pages' markup.
 */
beforeEach(function () {
    $this->vols = Team::factory()->create([
        'id' => 2633, 'slug' => 'tennessee-volunteers',
        'display_name' => 'Tennessee Volunteers', 'location' => 'Tennessee',
    ]);

    TeamSeason::create(['team_id' => 2633, 'season_year' => 2026, 'classification' => 'FBS']);

    $season = Season::factory()->create([
        'year' => 2026, 'type' => Season::REGULAR,
        'start_date' => '2026-08-29', 'end_date' => '2026-12-12',
    ]);

    Game::factory()->create([
        'season_id' => $season->id,
        'home_team_id' => 2633, 'away_team_id' => 2633,
        'kickoff_at' => '2026-09-05 19:30:00', 'completed' => false,
    ]);
});

function freshlyOnboarded(): User
{
    $user = User::factory()->create(['onboarded_at' => now()]);
    $user->followedTeams()->attach([2633 => ['position' => 1]]);

    return $user;
}

describe('eligibility', function () {
    it('mounts exactly when the wizard hands off: onboarded, first team, never toured', function () {
        $this->actingAs(freshlyOnboarded())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-guided-tour', escape: false);
    });

    it('never mounts for a guest', function () {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-guided-tour', escape: false);
    });

    it('stays down once toured', function () {
        $user = freshlyOnboarded();
        $user->forceFill(['tour_completed_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-guided-tour', escape: false);
    });

    it('waits for a first team — the coach marks need something to point at', function () {
        $user = User::factory()->create(['onboarded_at' => now()]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-guided-tour', escape: false);
    });

    it('can be pulled by the flag without a deploy', function () {
        Feature::define('guided-tour', fn (): bool => false);

        $this->actingAs(freshlyOnboarded())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-guided-tour', escape: false);
    });

    it('replays for a toured user via ?tour=1', function () {
        $user = freshlyOnboarded();
        $user->forceFill(['tour_completed_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('home', ['tour' => 1]))
            ->assertOk()
            ->assertSee('data-guided-tour', escape: false);
    });
});

describe('completion', function () {
    it('stamps finishing and skipping alike', function () {
        $user = freshlyOnboarded();

        Livewire::actingAs($user)->test('tour')->call('complete');

        expect($user->fresh()->hasToured())->toBeTrue();
    });

    it('lets the first stamp win, so a replay does not rewrite history', function () {
        $user = freshlyOnboarded();
        $user->forceFill(['tour_completed_at' => now()->subDay()])->save();

        $first = $user->fresh()->tour_completed_at;

        Livewire::actingAs($user)->test('tour')->call('complete');

        expect($user->fresh()->tour_completed_at->equalTo($first))->toBeTrue();
    });
});

describe('targets', function () {
    it('finds every Home spotlight target in the markup', function () {
        $html = $this->actingAs(freshlyOnboarded())
            ->get(route('home'))
            ->assertOk()
            ->content();

        // The swiper, the search bar (phone) AND the header palette (desktop):
        // one key, whichever element is visible at the reader's width.
        expect($html)->toContain('data-tour="glance"')
            ->and(substr_count($html, 'data-tour="search"'))->toBeGreaterThanOrEqual(2);
    });

    it('marks both navs so the tour works at every width', function () {
        $html = $this->actingAs(freshlyOnboarded())
            ->get(route('home'))
            ->assertOk()
            ->content();

        // Bottom tab below sm, header chip above — same key, twice each.
        foreach (['scores', 'league'] as $key) {
            expect(substr_count($html, 'data-tour="'.$key.'"'))->toBeGreaterThanOrEqual(2);
        }

        expect($html)->toContain('data-tour="account"');
    });

    it('holds back while the signup wizard is on screen', function () {
        // The tour checks for this attribute before auto-starting; the wizard
        // dispatches start-tour when it closes. Both halves are markup.
        $html = $this->actingAs(freshlyOnboarded())
            ->get(route('home'))
            ->assertOk()
            ->content();

        expect($html)->toContain('data-onboarding-overlay')
            ->and($html)->toContain('start-tour');
    });
});

describe('the voice', function () {
    it('speaks every step in every register, escalating rather than repeating', function () {
        foreach (['glance', 'search', 'scores', 'league', 'account', 'install'] as $step) {
            foreach (['heading', 'body'] as $part) {
                $key = "tour.{$step}.{$part}";

                $pg = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::Pg]));
                $r = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::R]));

                expect($pg)->not->toBe('')
                    ->and($r)->not->toBe('')
                    ->and($r)->not->toBe($pg);
            }
        }
    });
});
