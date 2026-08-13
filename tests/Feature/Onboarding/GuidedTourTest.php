<?php

use App\Actions\GrantWalletEntry;
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
    it('mounts exactly when the wizard hands off: onboarded, never toured', function () {
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

    it('no longer waits for a first team — the placeholder card gives the marks a target', function () {
        /*
         * This test used to pin the OPPOSITE: zero teams meant no swiper, no
         * glance anchor, no tour — so skipping the picker silently cost the
         * walkthrough. Bandwagon State fills the slot now, so a skipper gets
         * the tour they were owed, pointed at the card that sells picking a
         * real team.
         */
        $user = User::factory()->create(['onboarded_at' => now()]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-guided-tour', escape: false)
            ->assertSee('data-tour="glance"', escape: false);
    });

    it('treats dismissing the front door as declining the tour', function () {
        /*
         * With the team requirement gone, the CTA's X would otherwise be
         * answered by an uninvited tour on the very next load — right after
         * the user waved the whole thing away. Account keeps the replay.
         */
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('home')->call('dismissOnboarding');

        expect($user->fresh()->hasToured())->toBeTrue()
            ->and($user->fresh()->hasOnboarded())->toBeTrue();
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
        // Picks rides the same per-area emission both navs already do; the
        // wallet chips render in Home's brand bar below `sm` and the layout
        // header above, the search step's two-surfaces pattern.
        foreach (['scores', 'picks', 'league', 'wallet'] as $key) {
            expect(substr_count($html, 'data-tour="'.$key.'"'))->toBeGreaterThanOrEqual(2);
        }

        expect($html)->toContain('data-tour="account"');
    });

    it('keeps the Blade and Alpine step lists identical', function () {
        /*
         * The tour's steps are defined TWICE in tour.blade.php — a Blade
         * array that renders the copy blocks by index, and an Alpine `keys`
         * array that walks the spotlights by index. A mismatch shows one
         * step's words over another step's highlight, and nothing errors.
         * Source-swept because no render can see the disagreement.
         */
        $source = file_get_contents(resource_path('views/livewire/tour.blade.php'));

        preg_match_all("/\\[((?:\\s*'[a-z-]+',?)+)\\s*\\]/", $source, $lists);

        $sequences = collect($lists[1])
            ->map(fn (string $list) => preg_match_all("/'([a-z-]+)'/", $list, $m) ? $m[1] : [])
            ->filter(fn (array $keys) => in_array('glance', $keys, true))
            ->values();

        expect($sequences)->toHaveCount(2)
            ->and($sequences[0])->toBe($sequences[1]);
    });

    it('holds back while the signup wizard is on screen, then starts after a beat', function () {
        /*
         * The tour checks the wizard's visibility before auto-starting; the
         * wizard dispatches start-tour when it closes. Both halves are
         * markup, and so is the MECHANISM, which shipped broken once: the
         * wizard is `position: fixed`, and a fixed element's offsetParent is
         * null even while it fills the screen — so an offsetParent check
         * never held back, and the tour launched on top of the picker the
         * moment a first team landed. getClientRects() is the check that
         * works for fixed elements, and every start routes through the
         * startSoon() beat so the reader sees Home before the coach marks
         * claim it. No test runner renders frames, so the strings are what
         * a feature test can hold.
         */
        $html = $this->actingAs(freshlyOnboarded())
            ->get(route('home'))
            ->assertOk()
            ->content();

        expect($html)->toContain('data-onboarding-overlay')
            ->and($html)->toContain('x-on:start-tour.window="startSoon()"')
            // The wizard AND the signup splash wear the holdoff attribute;
            // the tour waits on whichever is visible.
            ->and($html)->toContain('[data-tour-holdoff]')
            ->and($html)->toContain('getClientRects().length');
    });
});

describe('personalization', function () {
    it("names the reader's own team in the search stop, and nobody when they skipped", function () {
        /*
         * The example is THEIR team — a canned school is somebody's rival,
         * and the pilot audience taught us which one. A skipper has no team,
         * and null means no data: the fallback line names nobody rather than
         * inventing an example.
         */
        $html = $this->actingAs(freshlyOnboarded())
            ->get(route('home'))
            ->assertOk()
            ->content();

        expect($html)->toContain('is enough to find Tennessee');

        $skipper = User::factory()->create(['onboarded_at' => now()]);

        $html = $this->actingAs($skipper)
            ->get(route('home'))
            ->assertOk()
            ->content();

        expect($html)->toContain('three letters is usually enough')
            ->and($html)->not->toContain('is enough to find');
    });

    it('mentions the seed XP at the wallet stop only when it was actually paid', function () {
        // A skipper tours too; the wallet stop must not congratulate them
        // for money that is not there.
        $seeded = freshlyOnboarded();

        app(GrantWalletEntry::class)->handle(
            $seeded,
            xp: GrantWalletEntry::FIRST_TEAM_XP,
            lattes: 0,
            reason: GrantWalletEntry::REASON_FIRST_TEAM,
            key: GrantWalletEntry::REASON_FIRST_TEAM,
        );

        $this->actingAs($seeded)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Picking your team paid it');

        $this->actingAs(freshlyOnboarded())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Picking your team paid it');
    });

    it('never says Georgia — in any stop, at any register', function () {
        /*
         * Swept at the Voice map so no fixture can hide it: the pilot group
         * wears orange, and the rival's name in tour copy reads as the app
         * picking a side against its own readers. The only way Georgia
         * appears in a tour is as the reader's OWN first team, via the
         * personalized example above.
         */
        $keys = ['tour.search.body_team', 'tour.wallet.seeded'];

        foreach (['glance', 'search', 'scores', 'picks', 'wallet', 'league', 'account', 'install'] as $step) {
            foreach (['heading', 'body'] as $part) {
                $keys[] = "tour.{$step}.{$part}";
            }
        }

        foreach ($keys as $key) {
            foreach (ContentRating::cases() as $rating) {
                $line = Voice::line($key, for: User::factory()->make(['content_rating' => $rating]));

                expect(stripos($line, 'georgia'))->toBeFalse();
            }
        }
    });
});

describe('the closing pitch', function () {
    it("carries the detected browser's install steps inside the card", function () {
        /*
         * The pitch says NOW, so the how renders right in the card: one
         * guide per mobile platform, Alpine showing the one detection found,
         * FxiOS checked before CriOS (every iOS browser is WebKit wearing a
         * badge, and the order is load-bearing) — plus a way out for
         * lied-about user agents. Detection is client-side, so source
         * strings are what a feature test can hold.
         */
        $html = $this->actingAs(freshlyOnboarded())
            ->get(route('home'))
            ->assertOk()
            ->content();

        foreach (['ios-safari', 'ios-chrome', 'ios-firefox', 'android'] as $platform) {
            expect($html)->toContain('wire:key="tour-guide-'.$platform.'"');
        }

        expect($html)->toContain('Add to Home Screen')
            ->and($html)->toContain('Different browser?');

        $source = file_get_contents(resource_path('views/livewire/tour.blade.php'));

        expect(strpos($source, 'FxiOS'))->not->toBeFalse()
            ->and(strpos($source, 'FxiOS'))->toBeLessThan(strpos($source, 'CriOS'));
    });
});

describe('the voice', function () {
    it('speaks every step in every register, escalating rather than repeating', function () {
        foreach (['glance', 'search', 'scores', 'picks', 'wallet', 'league', 'account', 'install'] as $step) {
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

    it('escalates the personalized lines too', function () {
        $replace = ['prefix' => 'Ten', 'team' => 'Tennessee', 'xp' => 25];

        foreach (['tour.search.body_team', 'tour.wallet.seeded'] as $key) {
            $pg = Voice::line($key, $replace, User::factory()->make(['content_rating' => ContentRating::Pg]));
            $r = Voice::line($key, $replace, User::factory()->make(['content_rating' => ContentRating::R]));

            expect($pg)->not->toBe('')
                ->and($r)->not->toBe('')
                ->and($r)->not->toBe($pg)
                // The replacements actually replace — a renamed placeholder
                // ships ":team" to a reader otherwise.
                ->and($pg)->not->toContain(':');
        }
    });
});
