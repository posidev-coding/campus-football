<?php

use App\Enums\ContentRating;
use App\Jobs\SyncTeamNews;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Models\Venue;
use App\Models\WalletEntry;
use App\Support\TeamGlance;
use App\Support\Voice;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->vols = Team::factory()->create([
        'id' => 2633, 'slug' => 'tennessee-volunteers',
        'display_name' => 'Tennessee Volunteers', 'location' => 'Tennessee',
    ]);
    $this->cats = Team::factory()->create([
        'id' => 96, 'slug' => 'kentucky-wildcats',
        'display_name' => 'Kentucky Wildcats', 'location' => 'Kentucky',
    ]);

    foreach ([2633, 96] as $id) {
        TeamSeason::create(['team_id' => $id, 'season_year' => 2026, 'classification' => 'FBS']);
    }

    /*
     * A real season with a schedule, so `scoreboardYear()` resolves to 2026.
     * Without it the picker list falls back to `config('cfb.season')`, finds
     * no FBS membership for that year, and comes back EMPTY — the search then
     * matches nothing and every picker assertion quietly tests an empty list.
     */
    $season = Season::factory()->create([
        'year' => 2026, 'type' => Season::REGULAR,
        'start_date' => '2026-08-29', 'end_date' => '2026-12-12',
    ]);

    Game::factory()->create([
        'season_id' => $season->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'kickoff_at' => '2026-09-05 19:30:00', 'completed' => false,
    ]);
});

describe('the call to action', function () {
    it('offers a guest a way in, without naming a form', function () {
        // "Get started" rather than "Create your account": the guest has not
        // decided to sign up yet, and the card is what does the persuading.
        //
        // Asserted on `Get started` alone, in both directions: the body copy
        // legitimately opens with the words "Add your team", so testing for
        // the absence of THAT would pass or fail on the prose rather than on
        // which branch rendered.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Get started');
    });

    it('sends a signed-in user with no teams straight at the picker', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Add your team')
            ->assertDontSee('Get started');
    });

    it('disappears once they follow a team, leaving the quiet slot behind', function () {
        $user = User::factory()->create();
        $user->followedTeams()->attach([2633 => ['position' => 1]]);

        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertDontSee('Get started')
            // The swiper's own add-slot is a convenience, not a prompt.
            ->assertSee('Add another');
    });
});

describe('dismissing it', function () {
    it('stamps onboarded_at for a signed-in user so it does not come back', function () {
        $user = User::factory()->create();

        /*
         * What the X removes is the blue CARD — pinned by its member heading,
         * because "Add your team" now legitimately lives on in the swiper's
         * own add slot, which a dismissed zero-team user keeps (beside the
         * Bandwagon State placeholder) rather than getting a bare page.
         */
        Livewire::actingAs($user)->test('home')
            ->call('dismissOnboarding')
            ->assertDontSee('Put your team up top')
            ->assertSee('Bandwagon State');

        expect($user->fresh()->hasOnboarded())->toBeTrue();

        // And it stays gone on a fresh load.
        $this->actingAs($user->fresh())->get(route('home'))->assertDontSee('Put your team up top');
    });

    it('remembers a guest in the session, which lapses naturally', function () {
        Livewire::test('home')->call('dismissOnboarding');

        expect(session('onboarding.dismissed'))->toBeTrue();
    });
});

describe('the guest flow', function () {
    it('opens on the first question, not on the picker', function () {
        Livewire::test('onboarding')->assertSet('step', 'name');
    });

    it('refuses to advance past an empty name', function () {
        Livewire::test('onboarding')
            ->call('next')
            ->assertHasErrors(['first_name', 'last_name'])
            ->assertSet('step', 'name');
    });

    it('catches a duplicate email on the credentials screen', function () {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test('onboarding')
            ->set('step', 'credentials')
            ->set('first_name', 'Test')->set('last_name', 'User')
            ->set('content_rating', 'pg13')
            ->set('email', 'taken@example.com')
            ->set('password', 'password123')->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasErrors('email');

        // Only the pre-existing account — the duplicate never landed.
        expect(User::count())->toBe(1);
    });

    it('walks all three steps, creates the account, and hands off to the moment', function () {
        Event::fake([Registered::class]);

        $component = Livewire::test('onboarding')
            ->set('first_name', 'Peyton')->set('last_name', 'Manning')->call('next')
            ->assertSet('step', 'rating')
            ->set('content_rating', 'r')->call('next')
            ->assertSet('step', 'credentials')
            ->set('email', 'peyton@example.com')
            ->set('password', 'password123')->set('password_confirmation', 'password123')
            ->call('register');

        // A FULL redirect, not navigate: registering flips the page's auth
        // state and every @auth region has to re-render. The hand-off rides
        // a session flash, never the URL — a query param was captured into
        // installed web clips and reopened the picker on every launch.
        $component->assertRedirect(route('home'));

        expect(session()->has('onboarding.moment'))->toBeTrue();

        $user = User::where('email', 'peyton@example.com')->first();

        expect($user)->not->toBeNull()
            ->and($user->first_name)->toBe('Peyton')
            ->and($user->content_rating->value)->toBe('r')
            // No handle question anywhere in the flow: null until claimed on
            // Account. Never a generated default — null means never claimed.
            ->and($user->handle)->toBeNull()
            ->and(auth()->id())->toBe($user->id);

        Event::assertDispatched(Registered::class);
    });
});

describe('the picker', function () {
    it('opens there directly for someone already signed in', function () {
        Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->assertSet('step', 'team')
            // The favorite question, asked out loud — one pick, one promise;
            // the five-slot education lives in the tour now.
            ->assertSee("Who's your team?")
            ->assertSee('you can add more later');
    });

    it('completes the moment on the first pick', function () {
        /*
         * No "add another", no Done: the first pick closes the overlay itself
         * — splash over the top, wizard closing beneath it, tour already
         * mounted from the re-render `team-followed` triggers.
         */
        Queue::fake();

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('onboarding')
            ->call('addTeam', 2633)
            ->assertDispatched('team-followed')
            ->assertDispatched('signup-splash')
            ->assertDispatched('close-onboarding');

        expect($user->followedTeams()->pluck('teams.id')->all())->toBe([2633]);
    });

    it('pays the first-team seed once — the one earn before verification', function () {
        /*
         * 25 XP for arriving with a team instead of skipping: a number in the
         * wallet worth protecting, planted before the verify gate. The
         * idempotency key makes it once-EVER — a second pass through the
         * moment (unfollow everything, come back) pays nothing.
         */
        Queue::fake();

        $user = User::factory()->unverified()->create();

        Livewire::actingAs($user)->test('onboarding')->call('addTeam', 2633);

        expect($user->walletTotals())->toBe(['xp' => 25, 'lattes' => 0]);

        $user->followedTeams()->detach();

        Livewire::actingAs($user)->test('onboarding')->call('addTeam', 96);

        expect(WalletEntry::where('user_id', $user->id)->count())->toBe(1);
    });

    it('pays nothing for skipping', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('onboarding')->call('done');

        expect(WalletEntry::where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('is not a back door around the news warm-up', function () {
        // Following is what fills a team's news tab. A second follow path that
        // skipped it would produce a team card whose News never populates.
        Queue::fake();

        Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->call('addTeam', 2633);

        Queue::assertPushed(SyncTeamNews::class, fn ($job) => $job->teamId === 2633);
    });

    it('marks them onboarded as soon as a team lands', function () {
        Queue::fake();

        $user = User::factory()->create();

        Livewire::actingAs($user)->test('onboarding')->call('addTeam', 2633);

        expect($user->fresh()->hasOnboarded())->toBeTrue();
    });

    it('stamps onboarded_at on Done even if they skipped', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('onboarding')
            ->call('done')
            ->assertDispatched('close-onboarding')
            // The skip path's real hand-off: Home re-renders on this, which
            // is what mounts the tour over the placeholder card.
            ->assertDispatched('onboarding-finished');

        expect($user->fresh()->hasOnboarded())->toBeTrue();
    });

    it('keeps the skip a whisper, and never shows a Done at all', function () {
        /*
         * The PICKER is the action: no primary button anywhere on the moment.
         * Skipping stays quiet, and there is no "Done" to reach because the
         * first pick completes the moment on its own.
         */
        Queue::fake();

        $component = Livewire::actingAs(User::factory()->create())->test('onboarding');

        $component->assertSee('Skip for now')->assertDontSee('Done');

        $component->call('addTeam', 2633)
            ->assertDontSee('Done')
            ->assertDispatched('close-onboarding');
    });

    it('never advances past credentials without registering', function () {
        /*
         * 'team' is a reachable state even though it left the counted list —
         * a plain next() from credentials would walk a validated guest into
         * the picker with no account behind them. register() is the only
         * door.
         */
        Livewire::test('onboarding')
            ->set('step', 'credentials')
            ->set('first_name', 'Test')->set('last_name', 'User')
            ->set('email', 'test@example.com')
            ->set('password', 'password-str0ng')
            ->set('password_confirmation', 'password-str0ng')
            ->call('next')
            ->assertSet('step', 'credentials');

        expect(User::count())->toBe(0);
    });

    it('has no Back from the picker, which sits past account creation', function () {
        Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->call('back')
            ->assertSet('step', 'team');
    });

    it('lets a freshly registered, unverified user follow a team', function () {
        /*
         * Verification is deliberately lenient — it gates Pick'em and XP
         * earning (bar the seeded first-team grant), never the hand-off — so
         * a new signup must sail through the moment without touching anything
         * verified-gated.
         */
        Queue::fake();

        $user = User::factory()->unverified()->create();

        Livewire::actingAs($user)->test('onboarding')->call('addTeam', 2633);

        expect($user->followedTeams()->count())->toBe(1);

        $this->actingAs($user)->get(route('home'))->assertOk();
    });
});

describe('Home stays in step', function () {
    it('renders the new glance card once the overlay reports a follow', function () {
        Queue::fake();

        $user = User::factory()->create();
        $user->followedTeams()->attach([2633 => ['position' => 1]]);

        Livewire::actingAs($user)
            ->test('home')
            ->dispatch('team-followed')
            ->assertSee('wire:key="glance-2633"', escape: false);
    });
});

describe('the device draft', function () {
    it('never offers the credentials step a way to write to storage', function () {
        /*
         * Two independent protections, both asserted because either alone
         * could be undone by a later edit:
         *
         *   - the allowlist names only the three non-credential fields
         *   - the credentials screen carries no save handler at all
         *
         * Caught in the browser first: the draft originally read from `$wire`,
         * whose bindings are DEFERRED, so it saved a step behind and could
         * attribute one field's text to another across a morph.
         */
        $html = Livewire::test('onboarding')->html();

        expect($html)
            ->toContain("fields: ['first_name', 'last_name', 'content_rating']")
            ->not->toContain("'password'")
            ->not->toContain('draft.email');

        // The save handler reads the ELEMENT that fired, not component state.
        expect($html)->toContain('save($event)');
    });

    it('remembers which step a returning visitor was on — never past the door', function () {
        /*
         * mount() resets to 'name', so a returning visitor re-clicked
         * through screens they had already answered. Only 'rating' is ever
         * restored: 'name' is the default, and 'credentials'/'team' stay
         * excluded by construction — a device draft must never deep-link
         * past registration.
         */
        $html = Livewire::test('onboarding')->html();

        expect($html)
            ->toContain("draft.step === 'rating'")
            ->toContain("['name', 'rating'].includes(value)")
            ->toContain('saveStep');
    });

    it('frames the rating step the way the register screen does', function () {
        // Same words on both doors: the label names the dial, the
        // description carries the promise, and the plain hint lowers the
        // stakes of choosing.
        Livewire::test('onboarding')->set('step', 'rating')
            ->assertSee('Trash talk')
            ->assertSee('This sets how hot it runs.')
            ->assertSee('You can change this any time on Account.');
    });

    it('gives every step its own key so one step cannot morph into another', function () {
        /*
         * Without these, Livewire reuses step one's input for step two — same
         * tag, same position — and the reused node kept its old binding long
         * enough for a keystroke to land on the previous field. Found in the
         * browser: typing a handle wrote to `first_name`.
         *
         * Asserted one step at a time, because only the current step renders.
         */
        foreach (['name', 'rating', 'credentials'] as $step) {
            $html = Livewire::test('onboarding')->set('step', $step)->html();

            expect($html)->toContain('wire:key="step-'.$step.'"');
        }

        // The picker is authed-only, so it gets its own pass.
        $html = Livewire::actingAs(User::factory()->create())->test('onboarding')->html();

        expect($html)->toContain('wire:key="step-team"');
    });

    it('warms up the splash for the favorite they just picked', function () {
        /*
         * The splash's phrases are server-rendered spans, so a Livewire
         * re-render keeps them naming the CURRENT favorite — Alpine only
         * tracks which span is showing. The travel phrase prefers the
         * favorite's inferred stadium, and the fixture's one game carries
         * no venue — so what this asserts is the FALLBACK to the pinned
         * `location`. The stadium path has its own test below.
         */
        Queue::fake();

        Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->call('addTeam', 2633)
            ->assertSee('Road-tripping to Tennessee')
            ->assertSeeHtml('data-signup-splash')
            /*
             * The pick path fires the splash from the SERVER now (the button
             * that carried the client dispatch is gone) — and Livewire morphs
             * before dispatching, so these re-personalized phrases are in the
             * DOM when begin() runs.
             */
            ->assertDispatched('signup-splash');
    });

    it('road-trips to the actual stadium once the games can name one', function () {
        /*
         * TeamVenue infers the favorite's home field from their non-neutral
         * home games; the splash prefers it over the school's name. Two
         * games at one venue is all the mode needs.
         */
        Queue::fake();

        Venue::create(['id' => 3843, 'name' => 'Neyland Stadium', 'city' => 'Knoxville', 'state' => 'TN']);

        $season = Season::firstWhere('year', 2026);

        foreach (['2026-09-05 19:00:00', '2026-09-12 15:30:00'] as $kickoff) {
            Game::factory()->create([
                'season_id' => $season->id,
                'home_team_id' => 2633, 'away_team_id' => 96,
                'venue_id' => 3843, 'kickoff_at' => $kickoff, 'completed' => false,
            ]);
        }

        Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->call('addTeam', 2633)
            ->assertSee('Road-tripping to Neyland Stadium');
    });

    it('warms up the splash for Bandwagon State when they skip', function () {
        // Zero teams: the same phrases resolve to the placeholder, whose
        // home field is wherever the winning is — so the quiet path gets
        // the joke instead of a quieter exit.
        Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->assertSee("Road-tripping to Wherever's Winning")
            ->assertSeeHtml("\$dispatch('signup-splash')");
    });

    it('counts a guest through three steps, and the moment through none', function () {
        /*
         * "Easy as 1-2-3" is the promise: guests fill a three-segment bar
         * (the count survives as sr-only text), and the team moment shows NO
         * counter for anyone — it sits past registration and is an arrival,
         * not a fourth chore. That includes the registration hand-off,
         * which used to advertise itself as "Step 5 of 5".
         */
        Livewire::test('onboarding')
            ->assertSee('Step 1 of 3')
            ->assertSeeHtml('wire:key="progress-1"');

        Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->assertDontSee('of 3');

        session()->flash('onboarding.moment', true);

        Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->assertDontSee('of 3');
    });

    it('slows the splash to a reading pace, and closes dark on the lattes', function () {
        /*
         * 2400ms a phrase — read it, smile, breathe — an extra ~2900ms hang
         * on the closer, and a forced `dark` class whatever the theme. The
         * timings and class are pinned as source strings because the
         * automated tab renders no frames to watch them in; the pace was
         * slowed twice on real-phone review, so a faster retune must be a
         * decision, not a drift. The order is the road trip: travel, field,
         * song, THEN the high-five, with the Beast Lattes holding the curtain.
         */
        $component = Livewire::actingAs(User::factory()->create())->test('onboarding');

        $component->assertSeeInOrder([
            'Road-tripping to',
            'Painting the end zones',
            'fight song',
            'High-fiving',
            'Beast Lattes',
        ]);

        expect($component->html())
            ->toContain(', 2400)')
            ->toContain('end(), 12500')
            ->toContain('class="dark fixed inset-0');
    });

    it('keeps every primary button beside its fields, not in a bottom rail', function () {
        /*
         * The old action rail pinned the button to the viewport bottom —
         * a reach away from the inputs, and behind the keyboard on a phone.
         * Now each step carries its own button inside its keyed div, and the
         * rail (with its top border) is gone entirely.
         */
        $guest = Livewire::test('onboarding')->html();

        expect($guest)
            ->toContain('wire:click="next"')
            ->not->toContain('border-t border-zinc-200 px-4 py-3');

        $credentials = Livewire::test('onboarding')->set('step', 'credentials')->html();

        expect($credentials)->toContain('wire:click="register"');
    });

    it('re-arms the picker for an invited registration that landed on the group', function () {
        /*
         * The invite path, end to end: the guest is routed to REGISTER,
         * registers, and redirectIntended lands them on /join — where the
         * onboarding.moment flash dies unread. The parked
         * `onboarding.pending` key re-arms the picker on their first Home
         * visit, and through the picker, the tour. Without it the PRIMARY
         * acquisition path produced members with zero follows, no tour,
         * and showTour suppressed forever.
         */
        session()->put('url.intended', '/join/ABCDEF');

        Livewire::test('auth.register')
            ->set('first_name', 'Invited')->set('last_name', 'Friend')
            ->set('email', 'invited@example.com')
            ->set('password', 'password123')->set('password_confirmation', 'password123')
            ->call('register')
            ->assertRedirect('/join/ABCDEF');

        expect(session()->get('onboarding.pending'))->toBeTrue()
            // The flash died on the intended landing; pending survives it.
            ->and(auth()->user()->hasOnboarded())->toBeFalse();

        // First Home visit: the wizard opens straight onto the picker...
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-onboarding-overlay', escape: false)
            ->assertSee('wire:key="step-team"', false);

        // ...and the parked key is consumed — read once, ever.
        expect(session()->has('onboarding.pending'))->toBeFalse();
    });

    it('never re-arms for an account that finished onboarding another way', function () {
        $user = User::factory()->create(['onboarded_at' => now()]);
        session()->put('onboarding.pending', true);

        $this->actingAs($user)->get(route('home'))->assertOk();

        // Pulled and discarded — a stale key cannot reopen the picker later.
        expect(session()->has('onboarding.pending'))->toBeFalse();
    });

    it('speaks the front door and picker in each register, escalating', function () {
        // LOUD chrome: all three registers side by side, R never just PG.
        foreach ([
            'onboarding.guest.heading', 'onboarding.guest.body',
            'onboarding.member.heading', 'onboarding.member.body',
            'onboarding.favorite', 'onboarding.picker', 'onboarding.name',
            'onboarding.rating', 'onboarding.credentials',
            'splash.warmup.greet', 'splash.warmup.travel', 'splash.warmup.field',
            'splash.warmup.song', 'splash.warmup.latte',
        ] as $key) {
            $pg = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::Pg]));
            $r = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::R]));

            expect($pg)->not->toBe('')
                ->and($r)->not->toBe('')
                ->and($r)->not->toBe($pg);
        }
    });

    it('lands the registration hand-off with the wizard already painted', function () {
        /*
         * register() flashes `onboarding.moment` and full-redirects to a
         * CLEAN home URL; on that landing Alpine boots with `open` true —
         * but an unconditional x-cloak hid the overlay until that boot, so
         * the HOME SCREEN flashed between steps four and five. On the
         * hand-off load the server renders the overlay visible from the
         * first frame; everywhere else the cloak stays, preventing the
         * opposite flash.
         */
        $overlayTag = function (string $html): string {
            preg_match('/<div[^>]*data-onboarding-overlay[^>]*>/', $html, $m);

            return $m[0] ?? '';
        };

        // Plain case FIRST: withSession() data persists across requests
        // within one test, so the hand-off's flag would leak into a later
        // plain GET and dissolve the comparison.
        $plain = $this->actingAs(User::factory()->create())
            ->get(route('home'))->assertOk()->content();

        $handoff = $this->actingAs(User::factory()->create())
            ->withSession(['onboarding.moment' => true])
            ->get(route('home'))->assertOk()->content();

        expect($overlayTag($handoff))->not->toBe('')->not->toContain('x-cloak')
            ->and($overlayTag($plain))->toContain('x-cloak');
    });

    it('keeps every island out of the flow — no phantom slot in Home\'s gap column', function () {
        /*
         * The component root and the splash wrapper are both `display:
         * contents`, because each renders only `fixed` children: a plain div
         * here is a ZERO-HEIGHT flex item in Home's gap-6 column, and gap
         * applies on both sides of it — which shipped as 48px of unexplained
         * air between the search bar and the team cards.
         */
        $html = Livewire::actingAs(User::factory()->create())->test('onboarding')->html();

        // Two `contents` wrappers: the component root and the splash island.
        // (No tag-scoped regex here — the splash tag's x-data holds arrow
        // functions, whose `=>` ends a naive [^>]* attribute match early.)
        expect($html)->toContain('x-on:signup-splash.window')
            ->and(substr_count($html, 'class="contents"'))->toBe(2);
    });

    it('never reopens the moment for a stale ?start=team — web clips captured that URL', function () {
        /*
         * The hand-off used to BE the querystring, and a home-screen install
         * captures the tab's URL — so the installed app launched into
         * "Who's your team?" forever, over a Home that already had the team,
         * and pull-to-refresh did the same in the tab. The flag lives in a
         * one-load session flash now; the param must be dead code, not
         * merely gated, so any clip that already captured it goes quiet.
         */
        $user = User::factory()->create(['onboarded_at' => now()]);
        $user->followedTeams()->attach([2633 => ['position' => 1]]);

        $overlayTag = function (string $html): string {
            preg_match('/<div[^>]*data-onboarding-overlay[^>]*>/', $html, $m);

            return $m[0] ?? '';
        };

        $html = $this->actingAs($user)
            ->get(route('home', ['start' => 'team']))->assertOk()->content();

        expect($overlayTag($html))->not->toBe('')->toContain('x-cloak')
            ->and($html)->not->toContain('open: true');
    });

    it('clears a finished draft for anyone already signed in', function () {
        // register() redirects, so the page unloads before anything
        // client-side can tidy up — the next authenticated load is what
        // stops a stranger's name sitting in a shared browser.
        $authed = Livewire::actingAs(User::factory()->create())->test('onboarding')->html();
        $guest = Livewire::test('onboarding')->html();

        expect($authed)->toContain('? clear() : restore()')
            ->and($guest)->toContain('? clear() : restore()');
    });
});

describe('the picker rows', function () {
    it('shows a team mark and a bold name, not a plain list item', function () {
        // These rows are the one thing on the screen a user is here to press,
        // so they carry the team's logo and read louder than body text.
        $this->vols->update([
            'logo' => 'https://espn/2633.png',
            'logo_dark' => 'https://espn/2633-dark.png',
        ]);

        $html = Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->set('teamQuery', 'Tennessee')
            ->html();

        expect($html)
            ->toContain('https://espn/2633.png')
            // The dark-mode mark is swapped by CSS, not by a second request.
            ->toContain('https://espn/2633-dark.png')
            ->toContain('min-w-0 flex-1 truncate font-semibold');
    });

    it('carries logos through the cached picker list', function () {
        /*
         * The list is CACHED, so it holds plain arrays — an Eloquent model
         * round-trips as __PHP_Incomplete_Class and fails on the second
         * request. The logo has to ride along in the array or the row has
         * nothing to render.
         */
        $this->vols->update(['logo' => 'https://espn/2633.png']);
        TeamGlance::flush();
        Cache::flush();

        $teams = collect(TeamGlance::fbsTeams(2026));
        $vols = $teams->firstWhere('id', 2633);

        expect($vols)->toHaveKeys(['id', 'name', 'logo', 'logo_dark'])
            ->and($vols['logo'])->toBe('https://espn/2633.png');
    });
});
