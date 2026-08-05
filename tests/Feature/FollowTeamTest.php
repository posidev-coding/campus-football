<?php

use App\Actions\FollowTeam;
use App\Actions\ReorderFollowedTeams;
use App\Actions\UnfollowTeam;
use App\Enums\ContentRating;
use App\Exceptions\FollowLimitReached;
use App\Jobs\SyncTeamNews;
use App\Models\Article;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Services\CfbCalendar;
use App\Services\Espn\Sync\SyncNews;
use App\Support\Voice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['id' => 61, 'slug' => 'georgia-bulldogs', 'display_name' => 'Georgia Bulldogs']);
});

it('dispatches a news fetch when a user follows a team', function () {
    // The whole point: a follow is the moment a team's history becomes worth
    // fetching, and ESPN's per-team feed carries stories the national one never
    // had — measured live, ~20-25 articles per team we did not already hold.
    Queue::fake();

    app(FollowTeam::class)->handle($this->user, $this->team);

    Queue::assertPushed(SyncTeamNews::class, fn (SyncTeamNews $job) => $job->teamId === 61);
});

it('records the follow', function () {
    Queue::fake();

    app(FollowTeam::class)->handle($this->user, $this->team);

    expect($this->user->followedTeams()->whereKey(61)->exists())->toBeTrue();
});

it('is idempotent, so following twice does not violate the unique key', function () {
    Queue::fake();

    $action = app(FollowTeam::class);
    $action->handle($this->user, $this->team);

    expect(fn () => $action->handle($this->user, $this->team))->not->toThrow(Throwable::class);
    expect($this->user->followedTeams()->count())->toBe(1);
});

it('deduplicates the job on the team, not the user', function () {
    /*
     * The invariant that stops a team going viral from costing one ESPN request
     * per follower. Five hundred people following Georgia after an upset is one
     * fetch, because the payload is identical for all of them.
     */
    $job = new SyncTeamNews(61);
    $other = new SyncTeamNews(333);

    expect($job->uniqueId())->toBe('61')
        ->and($job->uniqueId())->not->toBe($other->uniqueId());
});

it('keeps the job timeout below the queue retry_after', function () {
    /*
     * v3's retry_after (90s) was SHORTER than every job timeout, so long jobs
     * were released back onto the queue and re-run while the first copy was
     * still executing — duplicate concurrent workers on the same endpoint.
     * The relationship is the invariant, not either number.
     */
    $timeout = (new SyncTeamNews(61))->timeout;

    // Checked against EVERY connection that defines one, not just the default:
    // the test suite runs on `sync`, which has no retry_after, so asserting on
    // the default alone would silently assert nothing in CI.
    $checked = 0;

    foreach (config('queue.connections') as $name => $connection) {
        if (! isset($connection['retry_after'])) {
            continue;
        }

        expect($timeout)->toBeLessThan(
            $connection['retry_after'],
            "Job timeout must stay under [{$name}] retry_after."
        );

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});

it('appends a new follow to the end of the list', function () {
    // A new follow is never assumed to outrank the teams already there.
    Queue::fake();

    $second = Team::factory()->create();

    app(FollowTeam::class)->handle($this->user, $this->team);
    app(FollowTeam::class)->handle($this->user, $second);

    expect($this->user->followedTeams()->pluck('teams.id')->all())->toBe([61, $second->id])
        ->and($this->user->followedTeams()->pluck('position')->all())->toBe([1, 2]);
});

it('unfollowing keeps the articles', function () {
    /*
     * Articles are shared across every user, and ESPN's window is only days
     * wide — an article we delete is one we can never fetch again.
     */
    Queue::fake();

    $article = Article::create(['espn_id' => 1, 'headline' => 'Dawgs win', 'published_at' => now()]);
    $article->teams()->attach(61);

    app(FollowTeam::class)->handle($this->user, $this->team);
    app(UnfollowTeam::class)->handle($this->user, $this->team);

    expect($this->user->followedTeams()->count())->toBe(0)
        ->and(Article::count())->toBe(1);
});

it('actually stores the team feed when the job runs', function () {
    Http::fake(['*' => Http::response([
        'articles' => [[
            'id' => 4242,
            'headline' => 'Georgia lands five-star',
            'published' => now()->toIso8601String(),
            'categories' => [
                // ESPN lists each team TWICE — once as the nickname and once as
                // the university — with the same teamId.
                ['type' => 'team', 'teamId' => 61],
                ['type' => 'team', 'teamId' => 61],
            ],
        ]],
    ])]);

    (new SyncTeamNews(61))->handle(app(SyncNews::class));

    expect(Article::where('espn_id', 4242)->exists())->toBeTrue()
        // Deduped: the double listing must not become two pivot rows.
        ->and(Article::where('espn_id', 4242)->first()->teams()->count())->toBe(1);
});

it('does nothing for a team that no longer exists', function () {
    Http::fake();

    (new SyncTeamNews(999999))->handle(app(SyncNews::class));

    Http::assertNothingSent();
});

it('lets a signed-in user follow from the team page', function () {
    Queue::fake();

    Livewire::actingAs($this->user)
        ->test('follow-button', ['team' => $this->team])
        ->assertSee('Follow')
        ->call('follow')
        ->assertSee('Following');

    Queue::assertPushed(SyncTeamNews::class);
});

it('sends a guest to log in rather than silently doing nothing', function () {
    Queue::fake();

    Livewire::test('follow-button', ['team' => $this->team])
        ->call('follow')
        ->assertRedirect(route('login'));

    Queue::assertNotPushed(SyncTeamNews::class);
});

it('renders the team news tab with an attached article', function () {
    // Regression: the article card renders team chips, and lazy loading is
    // disabled, so this 500'd until `teams` was eager-loaded. The original
    // tests all used fixtures with no articles, which never reached the card.
    Queue::fake();

    $article = Article::create(['espn_id' => 9, 'headline' => 'Dawgs land a five-star', 'published_at' => now()]);
    $article->teams()->attach(61);

    Livewire::test('team', ['team' => $this->team])
        ->set('tab', 'news')
        ->assertOk()
        ->assertSee('Dawgs land a five-star');
});

it('dispatches rather than blocking the team page render on ESPN', function () {
    /*
     * This ran inline behind a cache at first, which put a 250 KB upstream
     * request in the middle of a page render — the exact pattern that made v3
     * collapse under load.
     */
    Queue::fake();
    Http::fake();

    Livewire::test('team', ['team' => $this->team])->set('tab', 'news')->assertOk();

    Http::assertNothingSent();
    Queue::assertPushed(SyncTeamNews::class);
});

describe('follow limit', function () {
    it('allows exactly the maximum', function () {
        $teams = Team::factory()->count(User::MAX_FOLLOWED_TEAMS)->create();

        foreach ($teams as $team) {
            app(FollowTeam::class)->handle($this->user, $team);
        }

        expect($this->user->followedTeams()->count())->toBe(User::MAX_FOLLOWED_TEAMS);
    });

    it('refuses the one after that', function () {
        Team::factory()->count(User::MAX_FOLLOWED_TEAMS)->create()
            ->each(fn (Team $t) => app(FollowTeam::class)->handle($this->user, $t));

        app(FollowTeam::class)->handle($this->user, Team::factory()->create());
    })->throws(FollowLimitReached::class);

    it('still accepts a team already followed while at the limit', function () {
        /*
         * The order of the two checks matters. Testing the cap first would
         * reject a re-follow of a team the user already has, so a user sitting
         * at exactly five could not press Follow on a team they already follow
         * — and the button would look broken.
         */
        $teams = Team::factory()->count(User::MAX_FOLLOWED_TEAMS)->create();
        $teams->each(fn (Team $t) => app(FollowTeam::class)->handle($this->user, $t));

        app(FollowTeam::class)->handle($this->user, $teams->first());

        expect($this->user->followedTeams()->count())->toBe(User::MAX_FOLLOWED_TEAMS);
    });

    it('reindexes to 1..N when a team is unfollowed, leaving no gap', function () {
        /*
         * Sparse positions still SORT correctly, which is what makes this easy
         * to leave broken. The cost lands on every later writer: appending
         * reads `max + 1` and would skip a number, and a reorder that assumes
         * contiguity would silently disagree with the database.
         */
        $teams = Team::factory()->count(4)->create();
        $teams->each(fn (Team $t) => app(FollowTeam::class)->handle($this->user, $t));

        app(UnfollowTeam::class)->handle($this->user, $teams[1]);

        expect($this->user->followedTeams()->pluck('position')->all())->toBe([1, 2, 3]);
    });

    it('frees a slot when a team is unfollowed', function () {
        $teams = Team::factory()->count(User::MAX_FOLLOWED_TEAMS)->create();
        $teams->each(fn (Team $t) => app(FollowTeam::class)->handle($this->user, $t));

        app(UnfollowTeam::class)->handle($this->user, $teams->first());
        app(FollowTeam::class)->handle($this->user, Team::factory()->create());

        expect($this->user->followedTeams()->count())->toBe(User::MAX_FOLLOWED_TEAMS);
    });
});

describe('following from the account screen', function () {
    it('follows a team from the search results', function () {
        Livewire::actingAs($this->user)
            ->test('account')
            ->set('teamSearch', 'Georgia')
            ->call('follow', $this->team->id);

        expect($this->user->followedTeams()->whereKey($this->team->id)->exists())->toBeTrue();
    });

    it('clears the query on success so the next search starts fresh', function () {
        Livewire::actingAs($this->user)
            ->test('account')
            ->set('teamSearch', 'Georgia')
            ->call('follow', $this->team->id)
            ->assertSet('teamSearch', '');
    });

    it('keeps the query when the follow fails', function () {
        // The reason it failed shows beside what they were reaching for, rather
        // than beside an empty box.
        Team::factory()->count(User::MAX_FOLLOWED_TEAMS)->create()
            ->each(fn (Team $t) => app(FollowTeam::class)->handle($this->user, $t));

        Livewire::actingAs($this->user)
            ->test('account')
            ->set('teamSearch', 'Georgia')
            ->call('follow', $this->team->id)
            ->assertSet('teamSearch', 'Georgia');
    });

    it('matches on part of a name, and only teams not already followed', function () {
        /*
         * The search offers FBS teams for the current season, so the fixture
         * needs the season-scoped membership row — a bare `Team` is invisible
         * to it. That scoping is the point: 854 teams is not a useful list.
         */
        $year = app(CfbCalendar::class)->scoreboardYear();

        Season::factory()->create(['year' => $year, 'type' => Season::REGULAR]);
        TeamSeason::create([
            'team_id' => $this->team->id,
            'season_year' => $year,
            'classification' => 'FBS',
        ]);

        Cache::flush();

        $component = Livewire::actingAs($this->user)
            ->test('account')
            ->set('teamSearch', 'georg');

        expect(collect($component->instance()->matches)->pluck('id'))
            ->toContain($this->team->id);

        $component->call('follow', $this->team->id)->set('teamSearch', 'georg');

        expect(collect($component->instance()->matches)->pluck('id'))
            ->not->toContain($this->team->id);
    });

    it('shows nothing until something is typed', function () {
        // All 136 FBS teams under the input would bury the rest of the card.
        $component = Livewire::actingAs($this->user)->test('account');

        expect($component->instance()->matches)->toBe([]);
    });

    it('explains the cap instead of failing silently', function () {
        Team::factory()->count(User::MAX_FOLLOWED_TEAMS)->create()
            ->each(fn (Team $t) => app(FollowTeam::class)->handle($this->user, $t));

        Livewire::actingAs($this->user)
            ->test('account')
            ->call('follow', $this->team->id)
            ->assertSet('followError', Voice::line('follow.limit', ['max' => User::MAX_FOLLOWED_TEAMS], $this->user));

        expect($this->user->followedTeams()->count())->toBe(User::MAX_FOLLOWED_TEAMS);
    });

    it('disables the input and says why once every spot is used', function () {
        Team::factory()->count(User::MAX_FOLLOWED_TEAMS)->create()
            ->each(fn (Team $t) => app(FollowTeam::class)->handle($this->user, $t));

        $this->actingAs($this->user)
            ->get(route('account'))
            ->assertOk()
            ->assertSee(Voice::line('teams.at_limit', ['max' => User::MAX_FOLLOWED_TEAMS], $this->user))
            ->assertDontSee('Search teams to follow');
    });

    it('clears that message when a slot is freed', function () {
        $teams = Team::factory()->count(User::MAX_FOLLOWED_TEAMS)->create();
        $teams->each(fn (Team $t) => app(FollowTeam::class)->handle($this->user, $t));

        // Unfollowing is exactly how a user acts on "unfollow one to make
        // room", so the warning must not outlive the condition.
        Livewire::actingAs($this->user)
            ->test('account')
            ->call('follow', $this->team->id)
            ->assertSet('followError', fn (string $m) => $m !== '')
            ->call('unfollow', $teams->first()->id)
            ->assertSet('followError', '');
    });

    it('says what pinning does, and leaves the cap to the counter', function () {
        $this->actingAs($this->user)
            ->get(route('account'))
            ->assertOk()
            ->assertSee(Voice::line('teams.subheading', for: $this->user))
            // The cap is carried by the `n / 5` counter beside the heading, so
            // the sentence does not repeat it.
            ->assertDontSee('You can follow up to');
    });
});

describe('ordering', function () {
    beforeEach(function () {
        Queue::fake();

        $this->teams = collect([$this->team])->merge(Team::factory()->count(2)->create());
        $this->teams->each(fn (Team $t) => app(FollowTeam::class)->handle($this->user, $t));
    });

    it('reorders to exactly the submitted order', function () {
        $reversed = $this->teams->pluck('id')->reverse()->values()->all();

        app(ReorderFollowedTeams::class)->handle($this->user, $reversed);

        expect($this->user->followedTeams()->pluck('teams.id')->all())->toBe($reversed)
            ->and($this->user->followedTeams()->pluck('position')->all())->toBe([1, 2, 3]);
    });

    it('refuses a list that is not exactly what the user follows', function () {
        /*
         * Reachable from a public Livewire method, so the client can send
         * anything: a team they do not follow (which would silently attach
         * it), or a short list (which would strand the rest at a stale
         * position). Rejecting outright beats applying half a bad order.
         */
        $original = $this->user->followedTeams()->pluck('teams.id')->all();
        $stranger = Team::factory()->create();

        $action = app(ReorderFollowedTeams::class);

        $action->handle($this->user, [$stranger->id, ...$original]);      // an extra
        $action->handle($this->user, array_slice($original, 0, 2));       // one missing
        $action->handle($this->user, [$original[0], $original[0], $original[1]]); // a repeat

        expect($this->user->followedTeams()->pluck('teams.id')->all())->toBe($original)
            ->and($this->user->followedTeams()->count())->toBe(3);
    });

    it('moves one team a single place for the keyboard path', function () {
        [$first, $second, $third] = $this->teams->pluck('id')->all();

        app(ReorderFollowedTeams::class)->move($this->user, $third, -1);

        expect($this->user->followedTeams()->pluck('teams.id')->all())->toBe([$first, $third, $second]);
    });

    it('ignores a move off either end rather than wrapping', function () {
        $original = $this->user->followedTeams()->pluck('teams.id')->all();

        app(ReorderFollowedTeams::class)->move($this->user, $original[0], -1);
        app(ReorderFollowedTeams::class)->move($this->user, $original[2], 1);

        expect($this->user->followedTeams()->pluck('teams.id')->all())->toBe($original);
    });

    it('drives the order the account screen renders', function () {
        $reversed = $this->teams->pluck('id')->reverse()->values()->all();

        $html = Livewire::actingAs($this->user)->test('account')->html();

        // Reorder, then confirm the rendered order followed.
        app(ReorderFollowedTeams::class)->handle($this->user, $reversed);

        $after = Livewire::actingAs($this->user)->test('account')->html();

        expect(strpos($after, 'wire:key="followed-'.$reversed[0].'"'))
            ->toBeLessThan(strpos($after, 'wire:key="followed-'.$reversed[2].'"'))
            ->and($html)->not->toBe($after);
    });
});

describe('editing your profile', function () {
    it('saves name, handle and rating', function () {
        Livewire::actingAs($this->user)
            ->test('account')
            ->set('first_name', 'Gunner')
            ->set('last_name', 'Stockton')
            ->set('handle', 'gunner11')
            ->set('content_rating', ContentRating::R->value)
            ->call('saveProfile')
            ->assertHasNoErrors();

        $this->user->refresh();

        expect($this->user->first_name)->toBe('Gunner')
            ->and($this->user->handle)->toBe('gunner11')
            ->and($this->user->content_rating)->toBe(ContentRating::R)
            ->and($this->user->name)->toBe('Gunner Stockton');
    });

    it('lets you save without changing your own handle', function () {
        // `ignore($user)` — without it, a plain unique rule reads the user's own
        // row as a collision and every save fails on a field they never touched.
        Livewire::actingAs($this->user)
            ->test('account')
            ->set('first_name', 'Changed')
            ->call('saveProfile')
            ->assertHasNoErrors();

        expect($this->user->fresh()->first_name)->toBe('Changed');
    });

    it('refuses a handle someone else already has', function () {
        User::factory()->create(['handle' => 'taken']);

        Livewire::actingAs($this->user)
            ->test('account')
            ->set('handle', 'taken')
            ->call('saveProfile')
            ->assertHasErrors('handle');
    });

    it('refuses one that differs only in case', function () {
        // The unique index sits on a case-insensitive collation, so `@Taken`
        // and `@taken` cannot both exist even if a future caller skips the form.
        User::factory()->create(['handle' => 'taken']);

        Livewire::actingAs($this->user)
            ->test('account')
            ->set('handle', 'TAKEN')
            ->call('saveProfile')
            ->assertHasErrors('handle');
    });

    it('strips what cannot be typed in a mention as you go', function () {
        Livewire::actingAs($this->user)
            ->test('account')
            ->set('handle', 'Gunner Stockton!')
            ->assertSet('handle', 'gunnerstockton');
    });

    it('opens the form on what is stored, not last time\'s typing', function () {
        Livewire::actingAs($this->user)
            ->test('account')
            ->set('first_name', 'Abandoned')
            ->call('fillProfileForm')
            ->assertSet('first_name', $this->user->first_name);
    });

    it('leaves email alone — changing it has to re-verify', function () {
        $this->actingAs($this->user)
            ->get(route('account'))
            ->assertOk()
            ->assertSee('Edit profile')
            ->assertDontSee('Email address');
    });
});

describe('voice', function () {
    it('speaks to the reader in their own register', function () {
        // The whole point of the setting: the same screen, three different
        // tones. If these ever collapse to one string the feature is decorative.
        $lines = collect(ContentRating::cases())->mapWithKeys(fn (ContentRating $r) => [
            $r->value => Voice::line('teams.empty', for: User::factory()->create(['content_rating' => $r])),
        ]);

        expect($lines->unique())->toHaveCount(3)
            ->and($lines->filter())->toHaveCount(3);
    });

    it('falls DOWN the ladder, never up', function () {
        /*
         * A PG reader must never be shown PG-13 copy — that is the one direction
         * that matters, because it is the direction that breaks a promise. An R
         * reader seeing a mild line is merely less funny.
         */
        $pg = User::factory()->create(['content_rating' => ContentRating::Pg]);

        expect(Voice::line('teams.empty', for: $pg))
            ->toBe('No teams yet. Search above to add your first.');
    });

    it('substitutes values into a line', function () {
        expect(Voice::line('follow.limit', ['max' => 5], User::factory()->create(['content_rating' => ContentRating::Pg])))
            ->toContain('5')
            ->not->toContain(':max');
    });

    it('gives a guest the default register rather than nothing', function () {
        // Registration and any signed-out surface still need words.
        auth()->logout();

        expect(Voice::line('teams.empty'))->not->toBe('');
    });

    it('returns an empty string for a key that does not exist', function () {
        // Better a blank than a raw key leaking into the interface.
        expect(Voice::line('nope.not.a.key'))->toBe('');
    });
});
