<?php

use App\Jobs\SyncTeamNews;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Support\TeamGlance;
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

        Livewire::actingAs($user)->test('home')
            ->call('dismissOnboarding')
            ->assertDontSee('Add your team');

        expect($user->fresh()->hasOnboarded())->toBeTrue();

        // And it stays gone on a fresh load.
        $this->actingAs($user->fresh())->get(route('home'))->assertDontSee('Add your team');
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

    it('catches a taken handle on the handle screen, not four screens later', function () {
        /*
         * The whole point of splitting the form. Discovering this at the end,
         * with everything else already typed, is the experience being avoided.
         */
        User::factory()->create(['handle' => 'taken']);

        Livewire::test('onboarding')
            ->set('first_name', 'Test')->set('last_name', 'User')->call('next')
            ->set('handle', 'taken')
            ->call('next')
            ->assertHasErrors('handle')
            ->assertSet('step', 'handle');
    });

    it('catches a duplicate email on the credentials screen', function () {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test('onboarding')
            ->set('step', 'credentials')
            ->set('first_name', 'Test')->set('last_name', 'User')
            ->set('handle', 'fresh')->set('content_rating', 'pg13')
            ->set('email', 'taken@example.com')
            ->set('password', 'password123')->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasErrors('email');

        expect(User::where('handle', 'fresh')->exists())->toBeFalse();
    });

    it('walks all four steps, creates the account, and hands off to the picker', function () {
        Event::fake([Registered::class]);

        $component = Livewire::test('onboarding')
            ->set('first_name', 'Peyton')->set('last_name', 'Manning')->call('next')
            ->assertSet('step', 'handle')
            ->set('handle', 'sheriff')->call('next')
            ->assertSet('step', 'rating')
            ->set('content_rating', 'r')->call('next')
            ->assertSet('step', 'credentials')
            ->set('email', 'peyton@example.com')
            ->set('password', 'password123')->set('password_confirmation', 'password123')
            ->call('register');

        // A FULL redirect, not navigate: registering flips the page's auth
        // state and every @auth region has to re-render.
        $component->assertRedirect(route('home', ['start' => 'team']));

        $user = User::where('handle', 'sheriff')->first();

        expect($user)->not->toBeNull()
            ->and($user->first_name)->toBe('Peyton')
            ->and($user->email)->toBe('peyton@example.com')
            ->and($user->content_rating->value)->toBe('r')
            ->and(auth()->id())->toBe($user->id);

        Event::assertDispatched(Registered::class);
    });
});

describe('the picker', function () {
    it('opens there directly for someone already signed in', function () {
        Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->assertSet('step', 'team')
            ->assertSee('Who do you follow?');
    });

    it('stays open after the first team so they can keep going', function () {
        Queue::fake();

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('onboarding')
            ->call('addTeam', 2633)
            // Still on the picker, with the team shown as done and room left.
            ->assertSet('step', 'team')
            ->assertSee('Tennessee Volunteers')
            ->assertSee('Done');

        expect($user->followedTeams()->pluck('teams.id')->all())->toBe([2633]);
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
            ->assertDispatched('close-onboarding');

        expect($user->fresh()->hasOnboarded())->toBeTrue();
    });

    it('lets a freshly registered, unverified user follow a team', function () {
        /*
         * User implements MustVerifyEmail and /account is behind `verified`,
         * but Home is not — so the hand-off must not route through anything
         * gated or a new signup would hit the verification wall mid-flow.
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
            ->toContain("fields: ['first_name', 'last_name', 'handle', 'content_rating']")
            ->not->toContain("'password'")
            ->not->toContain('draft.email');

        // The save handler reads the ELEMENT that fired, not component state.
        expect($html)->toContain('save($event)');
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
        foreach (['name', 'handle', 'rating', 'credentials'] as $step) {
            $html = Livewire::test('onboarding')->set('step', $step)->html();

            expect($html)->toContain('wire:key="step-'.$step.'"');
        }
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
