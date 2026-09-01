<?php

use App\Actions\GrantWalletEntry;
use App\Enums\ContentRating;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Support\Tours;
use App\Support\Voice;
use Illuminate\Support\Str;
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

    it('renders the copy blocks and the spotlight walk off ONE step list', function () {
        /*
         * The steps used to be typed TWICE in tour.blade.php — a Blade array
         * for the copy blocks and an Alpine `keys` array for the spotlights,
         * both walked by index — and a mismatch showed one stop's words over
         * another stop's highlight without erroring. This test swept the two
         * level.
         *
         * A SECOND WALK made that a second chance to mistype it, so the
         * duplication is gone: the lists live on App\Support\Tours, the view
         * reads one of them into `$steps`, and Alpine's copy is rendered
         * FROM that with @js. The sweep now holds the shape that makes the
         * old bug unreachable rather than policing two copies of it.
         */
        $source = file_get_contents(resource_path('views/livewire/tour.blade.php'));

        expect($source)->toContain('keys: @js($steps)')
            ->and($source)->toContain('$steps = $this->steps;')
            ->and(array_keys(Tours::WALKS))->toBe([Tours::HOME, Tours::PICKS])
            // The Home walk still opens and closes where it always did.
            ->and(Tours::WALKS[Tours::HOME][0])->toBe('glance')
            ->and(Tours::WALKS[Tours::HOME][count(Tours::WALKS[Tours::HOME]) - 1])->toBe('install');

        // No walk repeats a stop: two copy blocks over one spotlight.
        foreach (Tours::WALKS as $walk => $steps) {
            expect(array_unique($steps))->toBe($steps, "{$walk} repeats a stop");
        }
    });

    it('writes every stop of every walk in every register', function () {
        /*
         * A stop is a `[data-tour]` key AND a Voice family, so a list gains
         * a stop and the copy map has to gain three registers. An unwritten
         * key resolves to '' and the card renders a hole with Next under it
         * — the coach mark equivalent of a blank screen.
         */
        foreach (Tours::WALKS as $walk => $steps) {
            foreach ($steps as $step) {
                foreach (['heading', 'body'] as $part) {
                    $line = Voice::line("tour.{$step}.{$part}", for: User::factory()->make([
                        'content_rating' => ContentRating::Pg,
                    ]));

                    expect($line)->not->toBe('', "tour.{$step}.{$part} is unwritten for {$walk}");
                }
            }
        }
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

describe('the coach marks stay on their target', function () {
    /*
     * Reported 2026-08-31 from a real phone: the shading did not contain the
     * favorite-teams card, and the verify banner above it was the suspect.
     * Both halves of the fix are client-side geometry, so these hold the
     * layer a feature test can — the markup and the source shape — and the
     * reasoning for each lives beside it in tour.blade.php.
     */
    it('holds the verify nudge down for the length of the walk', function () {
        // Unverified AND freshly onboarded: the nudge would render, and the
        // tour is about to walk to the card directly beneath it.
        $walker = User::factory()->unverified()->create(['onboarded_at' => now()]);
        $walker->followedTeams()->attach([2633 => ['position' => 1]]);

        $this->actingAs($walker)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-guided-tour', escape: false)
            ->assertDontSee('data-verify-callout');
    });

    it('puts it back the moment the walk is finished or skipped', function () {
        $walker = User::factory()->unverified()->create(['onboarded_at' => now()]);
        $walker->followedTeams()->attach([2633 => ['position' => 1]]);

        // Same account, one stamp later: still unverified, so the only thing
        // that changed is the tour being over.
        $walker->forceFill(['tour_completed_at' => now()])->save();

        $this->actingAs($walker)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-guided-tour', escape: false)
            ->assertSee('data-verify-callout', escape: false);
    });

    it('restores it without a navigation, on the event the tour dispatches', function () {
        /*
         * The reader who skips is standing on Home and never reloads it, so
         * the row has to come back on the event alone. Skipping stamps
         * first and announces second — see finish() — which is what this
         * asserts by stamping before dispatching.
         */
        $walker = User::factory()->unverified()->create(['onboarded_at' => now()]);
        $walker->followedTeams()->attach([2633 => ['position' => 1]]);

        $home = Livewire::actingAs($walker)->test('home');

        expect($home->html())->not->toContain('data-verify-callout');

        $walker->forceFill(['tour_completed_at' => now()])->save();

        $home->dispatch('tour-finished');

        expect($home->html())->toContain('data-verify-callout');
    });

    it('lets a replay end too, rather than holding the nudge down forever', function () {
        /*
         * showTour short-circuits on the ?tour=1 replay flag before it ever
         * reads hasToured(), so a replay that only stamped would leave the
         * flag — and the hidden nudge — standing after its own last card.
         * Clearing it also strips ?tour=1, so a reload does not restart a
         * walk the reader just closed.
         */
        $replayer = User::factory()->unverified()->create([
            'onboarded_at' => now(), 'tour_completed_at' => now(),
        ]);
        $replayer->followedTeams()->attach([2633 => ['position' => 1]]);

        // ?tour=1, not a mount argument: #[Url] hydrates from the
        // querystring, which is the only place the replay flag ever comes
        // from.
        $home = Livewire::actingAs($replayer)
            ->withQueryParams(['tour' => '1'])
            ->test('home');

        expect($home->get('tourReplay'))->toBeTrue()
            ->and($home->html())->not->toContain('data-verify-callout');

        $home->dispatch('tour-finished');

        expect($home->get('tourReplay'))->toBeFalse()
            ->and($home->html())->toContain('data-verify-callout');
    });

    it('re-measures on movement instead of re-walking the step', function () {
        /*
         * go() SCROLLS; measure() only reads. Resize used to re-run go(),
         * which fed itself on a phone — scrollIntoView collapses the iOS URL
         * bar, the collapse fires resize, resize scrolled again. The split is
         * the fix, and the direction of it is what a source sweep can hold:
         * scrollIntoView must live in go() and nowhere in measure().
         */
        $source = file_get_contents(resource_path('views/livewire/tour.blade.php'));

        expect($source)->toContain('x-on:resize.window="if (open) measure()"')
            ->and($source)->not->toContain('x-on:resize.window="if (open) go(step, 1)"');

        $measure = strpos($source, 'measure() {');
        $next = strpos($source, 'next() {', $measure);
        $body = substr($source, $measure, $next - $measure);

        expect($measure)->not->toBeFalse()
            ->and($body)->not->toContain('scrollIntoView')
            ->and($body)->toContain('getBoundingClientRect');
    });

    it('follows a page that moves under it, by scroll and by reflow alike', function () {
        /*
         * `overflow: hidden` on <html> is x-trap.noscroll's desktop scroll
         * lock and iOS Safari does not honor it for touch, so the page
         * behind the scrim really can move — capture-phase, because the
         * scroll originates on a descendant. And a reflow with no scroll and
         * no resize at all (an image landing, a morph, a cloak resolving)
         * moves the target just as far, which is what the observer is for.
         * Neither delivers in an automated tab, so the wiring is the
         * assertion; measure() is pinned as pure by the sweep above.
         */
        $source = file_get_contents(resource_path('views/livewire/tour.blade.php'));

        expect($source)->toContain("window.addEventListener('scroll', this.onMove, { capture: true, passive: true })")
            ->and($source)->toContain('new ResizeObserver')
            ->and($source)->toContain('this.observer.observe(document.body)')
            // Torn down with the tour, or the next screen pays for it.
            ->and($source)->toContain("window.removeEventListener('scroll', this.onMove, { capture: true })")
            ->and($source)->toContain('this.observer.disconnect()');
    });

    it('measures the first box only after the scroll lock has reflowed', function () {
        /*
         * x-trap.noscroll runs off the same `open` write and Alpine flushes
         * it a microtask LATER, so a box measured inline was measured before
         * disableScrolling() put `overflow: hidden` and a scrollbar's worth
         * of padding-right on <html> — and the reflow that followed slid the
         * page out from under a spotlight already pinned to the old numbers.
         *
         * A plain task, and NOT $nextTick: Livewire holds Alpine's tick
         * stack across a commit, and a held tick is released only by
         * whatever commits next. Measured through $nextTick this lost the
         * race on a cold Home — the tour opened with a null box, the scrim
         * covered the page, and the card it was spotlighting sat under it
         * until the verify poll committed thirty seconds later. Caught in
         * the device harness on 2026-08-31, which is the only place it is
         * visible; both spellings pass every assertion but this one.
         */
        $source = file_get_contents(resource_path('views/livewire/tour.blade.php'));

        expect($source)->toContain('setTimeout(() => this.go(0, 1))')
            ->and($source)->not->toContain('$nextTick(() => this.go(0, 1))')
            // Nothing on the tour's path may ride a holdable tick: the
            // card-height correction arriving whenever the next commit
            // happens is a card left hanging off the bottom of the screen,
            // and a held tick at init would hold the whole tour behind it.
            ->and($source)->not->toContain('$nextTick(')
            ->and($source)->not->toContain("this.open = true\n            this.go(0, 1)");
    });

    it('stamps before it announces, so Home reads a written row', function () {
        /*
         * Home's showTour reads hasToured() from the database. Dispatching
         * first pooled both calls into one round trip and Home re-rendered
         * against a row not yet written, leaving the nudge it was told to
         * restore hidden. Ordering inside an Alpine method is source, so a
         * sweep is what holds it.
         */
        $source = file_get_contents(resource_path('views/livewire/tour.blade.php'));

        $finish = strpos($source, 'async finish() {');
        $stamp = strpos($source, 'await this.$wire.complete()', $finish);
        $announce = strpos($source, "this.\$dispatch('tour-finished')", $finish);

        expect($finish)->not->toBeFalse()
            ->and($stamp)->not->toBeFalse()
            ->and($announce)->not->toBeFalse()
            ->and($stamp)->toBeLessThan($announce);
    });

    it('rounds the spotlight to whole pixels', function () {
        // A rect on a half pixel puts a soft edge either side of a 2px ring,
        // which reads as the highlight not quite containing the card.
        $source = file_get_contents(resource_path('views/livewire/tour.blade.php'));

        expect($source)->toContain('top: Math.round(r.top - 8)')
            ->and($source)->toContain('height: Math.round(r.height + 16)');
    });

    it('eases between marks but tracks movement instantly', function () {
        /*
         * The 300ms ease is what makes walking between coach marks read as
         * one light moving; the same ease applied to a scroll correction is
         * a spotlight lagging a third of a second behind the card — the same
         * complaint as being offset. So the transition is BOUND, not static.
         */
        $source = file_get_contents(resource_path('views/livewire/tour.blade.php'));

        expect($source)->toContain("x-bind:class=\"tracking ? '' : 'transition-all duration-300'\"")
            ->and($source)->toContain('this.tracking = true')
            ->and($source)->toContain('this.tracking = false');

        // And the spotlight must not also carry it statically, or the
        // binding removes a class the element keeps anyway.
        $spotlight = strpos($source, 'ring-2 ring-blue-500');
        $line = substr($source, $spotlight, 120);

        expect($line)->not->toContain('transition-all');
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
            credits: 0,
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

    it('never says Georgia — onboarding, splash and home copy included', function () {
        /*
         * Swept at the Voice map so no fixture can hide it: the pilot group
         * wears orange, and the rival's name in canned copy reads as the
         * app picking a side against its own readers. Extended past the
         * tour to every family a NEW user meets — the whole onboarding
         * funnel is the audience the sweep protects. Iterating the LINES
         * constant means a key added tomorrow is swept tomorrow.
         *
         * `picks.` and `lobby.` joined the list on 2026-08-31: the flag
         * flips this week, so the pick'em front doors ARE what a new
         * user meets now. Pre-checked clean at the time — the sweep is
         * here to hold the ground, not to fix it.
         */
        $lines = (new ReflectionClass(Voice::class))->getConstant('LINES');

        $violations = [];

        foreach ($lines as $key => $variants) {
            if (! Str::startsWith($key, ['tour.', 'onboarding.', 'splash.', 'home.', 'picks.', 'lobby.'])) {
                continue;
            }

            foreach ($variants as $register => $line) {
                if (stripos($line, 'georgia') !== false) {
                    $violations[] = "{$key}.{$register}";
                }
            }
        }

        expect($violations)->toBe([], implode(' | ', $violations));
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

    it('stamps completion on ARRIVING at the pitch, not only on Done', function () {
        /*
         * The install stop's happy path exits through the OS: the reader
         * follows the share-sheet steps, taps the new icon, and never
         * touches another tour control. The web clip inherits the session
         * cookie but no client state, so the server-side stamp is the only
         * completion signal the installed app can see — unwritten, it
         * relaunched into a replay of the tour just finished. The stamp is
         * client wiring inside go(), so the source position is what a
         * feature test can hold: complete() must fire inside the install
         * branch BEFORE the card renders.
         */
        $source = file_get_contents(resource_path('views/livewire/tour.blade.php'));

        $branch = strpos($source, "if (key === 'install')");
        $stamp = strpos($source, 'this.$wire.complete()', $branch);
        $render = strpos($source, 'this.step = index', $branch);

        expect($branch)->not->toBeFalse()
            ->and($stamp)->not->toBeFalse()
            ->and($render)->not->toBeFalse()
            ->and($stamp)->toBeLessThan($render);
    });

    it('closes as installed the moment Chromium announces one', function () {
        // `appinstalled` (relayed by app.js as cfb:install-done) can fire
        // mid-tour when the captured prompt is accepted; the card closes as
        // installed instead of pitching over the install animation.
        $this->actingAs(freshlyOnboarded())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('x-on:cfb:install-done.window', escape: false);
    });
});

describe('the voice', function () {
    it('speaks every step in every register, escalating rather than repeating', function () {
        foreach (['glance', 'search', 'scores', 'picks', 'room', 'wallet', 'league', 'account', 'install'] as $step) {
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

    it('names where a Tallboy is SPENT, not just that one exists', function () {
        /*
         * The promise-debt this economy exists to pay off. "The app runs on
         * Tallboys" over a balance with nowhere to go was the loudest claim
         * in the app attached to the emptiest fact — and a stop that still
         * said only "earn them" would leave a private-league reader holding
         * a number they cannot place.
         *
         * Both halves, in every register: earned everywhere, spent in the
         * LOBBY. The named place is the load-bearing word; the slang around
         * it is free to vary.
         */
        foreach ([ContentRating::Pg, ContentRating::Pg13, ContentRating::R] as $rating) {
            $body = Voice::line('tour.wallet.body', for: User::factory()->make(['content_rating' => $rating]));

            expect($body)->toContain('Lobby')
                ->and(str_contains($body, 'Earn') || str_contains($body, 'earn'))->toBeTrue()
                ->and(str_contains($body, 'spend') || str_contains($body, 'Spend'))->toBeTrue();
        }
    });

    it('closes the signup splash on the sink, in every register', function () {
        // The closer holds the screen longest because the last thing read
        // is the thing remembered — so it is the one phrase that must not
        // sell a currency with nothing to buy.
        foreach ([ContentRating::Pg, ContentRating::Pg13, ContentRating::R] as $rating) {
            expect(Voice::line('splash.warmup.tallboy', for: User::factory()->make(['content_rating' => $rating])))
                ->toContain('Tallboy')
                ->toContain('Lobby');
        }
    });

    it('speaks the live picks stop in every register, escalating', function () {
        foreach (['tour.picks_live.heading', 'tour.picks_live.body'] as $key) {
            $pg = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::Pg]));
            $r = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::R]));

            expect($pg)->not->toBe('')
                ->and($r)->not->toBe('')
                ->and($r)->not->toBe($pg);
        }
    });

    it('offers the room beat only when the flag is open, with a door in the card', function () {
        /*
         * The room stop's gate is its ANCHOR: 'room' rides both step lists
         * unconditionally (the parity sweep holds), the teaser card wears
         * data-tour="room" only while the flag is open, and a stop with no
         * visible target steps over itself. Seating the reader in a
         * contest is the first-week retention hinge — the card carries the
         * walk, not just the words.
         */
        config()->set('cfb.pickem_open', true);

        $reader = freshlyOnboarded();
        $this->actingAs($reader)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-tour="room"', escape: false)
            ->assertSee(Voice::line('tour.room.heading', for: $reader))
            ->assertSee('Take me there');

        // Flag closed: no anchor on Home, so the beat self-skips.
        config()->set('cfb.pickem_open', false);

        $this->actingAs(freshlyOnboarded())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-tour="room"', escape: false);
    });

    it('walks launch day to a picks stop that says it is live', function () {
        /*
         * "Picks are coming", walked to the center tab on launch day, was
         * the tour lying about the product's whole point. The branch reads
         * the commit-11 config mirror, so the flip reaches the very next
         * tour with no Pennant purge in between.
         */
        config()->set('cfb.pickem_open', true);

        $reader = freshlyOnboarded();
        $this->actingAs($reader)
            ->get(route('home'))
            ->assertOk()
            ->assertSee(Voice::line('tour.picks_live.heading', for: $reader))
            ->assertDontSee('holding the seat');

        // Closed again: the promise copy still stands for a civilian.
        config()->set('cfb.pickem_open', false);

        $waiting = freshlyOnboarded();
        $this->actingAs($waiting)
            ->get(route('home'))
            ->assertOk()
            ->assertSee(Voice::line('tour.picks.heading', for: $waiting));
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
