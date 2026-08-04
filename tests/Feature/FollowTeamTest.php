<?php

use App\Actions\FollowTeam;
use App\Actions\SetFavoriteTeam;
use App\Actions\UnfollowTeam;
use App\Jobs\SyncTeamNews;
use App\Models\Article;
use App\Models\Team;
use App\Models\User;
use App\Services\Espn\Sync\SyncNews;
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

it('setting a favourite team also follows and fetches', function () {
    // Nobody picks a favourite team and then expects not to be following it.
    Queue::fake();

    app(SetFavoriteTeam::class)->handle($this->user, $this->team);

    expect($this->user->fresh()->favorite_team_id)->toBe(61)
        ->and($this->user->followedTeams()->whereKey(61)->exists())->toBeTrue();

    Queue::assertPushed(SyncTeamNews::class);
});

it('clearing a favourite team does not dispatch', function () {
    Queue::fake();

    app(SetFavoriteTeam::class)->handle($this->user, null);

    expect($this->user->fresh()->favorite_team_id)->toBeNull();
    Queue::assertNotPushed(SyncTeamNews::class);
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
