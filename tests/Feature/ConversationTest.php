<?php

use App\Actions\DeleteConversationPost;
use App\Actions\PostToConversation;
use App\Enums\ContentRating;
use App\Enums\ContestMode;
use App\Exceptions\CannotModeratePost;
use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PostingTooFast;
use App\Models\Article;
use App\Models\ConversationPost;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Team;
use App\Models\User;
use App\Support\Voice;
use Illuminate\Database\ClassMorphViolationException;
use Livewire\Livewire;

/*
 * THE CONVERSATION — one polymorphic surface at exactly three scopes.
 *
 * Two disciplines carry most of these tests. The first: reading is open to
 * everybody and the gate is on the WRITE, inside the Action, because the
 * composer being hidden is presentation and this method is public. The
 * second: posts are IMMUTABLE, so a flood is permanent in a way a pick is
 * not — the limiter and the delete-only moderation path are the whole
 * safety model, and both are held here.
 */

/** A game with no teams — the scope, not the matchup, is what is under test. */
function talkGame(): Game
{
    return Game::factory()->create([
        'home_team_id' => null,
        'away_team_id' => null,
        // Pinned: GameFactory otherwise scatters kickoff across four months
        // and this fixture would drift into every date-window query.
        'kickoff_at' => '2026-09-05 19:30:00',
    ]);
}

it('writes a post to each of the three scopes', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $user->id]);

    $action = app(PostToConversation::class);

    $onGame = $action->handle($user, talkGame(), 'That call was a crime.');
    $onTeam = $action->handle($user, Team::factory()->create(), 'Schedule is soft.');
    $onGroup = $action->handle($user, $group, 'Everyone here is 0-3.');

    // The discriminator is the short alias the morph map sanctions, never a
    // class name — the column is 10 characters wide for exactly that reason.
    expect($onGame->topic_type)->toBe('game')
        ->and($onTeam->topic_type)->toBe('team')
        ->and($onGroup->topic_type)->toBe('group')
        ->and($onGroup->topic_id)->toBe($group->id);
});

it('refuses a scope that is not one of the three', function () {
    $user = User::factory()->create();

    // An unmapped model never gets as far as the whitelist: enforceMorphMap
    // throws inside getMorphClass(), which is the louder failure and the one
    // we want.
    expect(fn () => app(PostToConversation::class)->handle($user, Article::factory()->create(), 'hi'))
        ->toThrow(ClassMorphViolationException::class);

    /*
     * User is the case the whitelist exists for. It is IDENTITY-mapped on
     * purpose — notifications, push_subscriptions and Pennant scopes already
     * store the FQCN — so getMorphClass() hands back a perfectly valid class
     * name for a model that must never carry a conversation. Only the
     * three-scope list catches it.
     */
    expect(fn () => app(PostToConversation::class)->handle($user, $user, 'hi'))
        ->toThrow(InvalidArgumentException::class);
});

it('gates the write on a verified email', function () {
    $user = User::factory()->unverified()->create();

    expect(fn () => app(PostToConversation::class)->handle($user, talkGame(), 'Let me in.'))
        ->toThrow(PickemParticipationGated::class);

    expect(ConversationPost::query()->count())->toBe(0);
});

it('gates the write on a claimed handle', function () {
    // The claim moment: the first pick OR the first post. A post with no name
    // on it is the thing this seam exists to prevent.
    $user = User::factory()->create(['handle' => null]);

    expect(fn () => app(PostToConversation::class)->handle($user, talkGame(), 'Anonymous take.'))
        ->toThrow(HandleRequired::class);
});

it('walls a group conversation to its members, and only a group', function () {
    $outsider = User::factory()->create();
    $group = Group::factory()->create();

    expect(fn () => app(PostToConversation::class)->handle($outsider, $group, 'Let me in.'))
        ->toThrow(NotGroupMember::class);

    // The same user, on the public scopes, is fine — a group is the ONE scope
    // with a membership wall because the group is the room.
    $post = app(PostToConversation::class)->handle($outsider, talkGame(), 'Public surface.');

    expect($post->exists)->toBeTrue();
});

it('trims the body and refuses one with nothing in it', function () {
    $user = User::factory()->create();
    $game = talkGame();

    $post = app(PostToConversation::class)->handle($user, $game, "  Vols.\n ");

    expect($post->body)->toBe('Vols.');

    expect(fn () => app(PostToConversation::class)->handle($user, $game, "   \n  "))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a body past the column width rather than letting MySQL truncate it', function () {
    // A silent truncation changes what somebody said. The check is here so the
    // 501st character is a refusal the writer can see.
    $user = User::factory()->create();

    $tooLong = str_repeat('a', PostToConversation::MAX_LENGTH + 1);

    expect(fn () => app(PostToConversation::class)->handle($user, talkGame(), $tooLong))
        ->toThrow(InvalidArgumentException::class);

    // The exact width still goes through.
    $post = app(PostToConversation::class)->handle($user, talkGame(), str_repeat('a', PostToConversation::MAX_LENGTH));

    expect(mb_strlen($post->body))->toBe(PostToConversation::MAX_LENGTH);
});

it('throttles a flood, and a refused post does not spend the budget', function () {
    $user = User::factory()->create();
    $game = talkGame();
    $action = app(PostToConversation::class);

    foreach (range(1, PostToConversation::MAX_PER_WINDOW) as $i) {
        $action->handle($user, $game, "Take number {$i}.");
    }

    expect(fn () => $action->handle($user, $game, 'One too many.'))
        ->toThrow(PostingTooFast::class);

    expect(ConversationPost::query()->count())->toBe(PostToConversation::MAX_PER_WINDOW);
})->group('throttle');

it('does not spend the limiter on a post that was never written', function () {
    // The hit lands on the way to a real row. A typo that trips validation
    // must not cost somebody their next minute of the argument.
    $user = User::factory()->create();
    $game = talkGame();
    $action = app(PostToConversation::class);

    foreach (range(1, 20) as $ignored) {
        try {
            $action->handle($user, $game, '   ');
        } catch (InvalidArgumentException) {
            // Expected: an empty body is refused before the limiter.
        }
    }

    // Still holding a full budget, because none of that was a post.
    $post = $action->handle($user, $game, 'A real one.');

    expect($post->exists)->toBeTrue();
});

it('spells the throttle window out rather than diffing two Carbons', function () {
    // `now()->addMinute()->diffInSeconds()` is NEGATIVE in Carbon 3, which
    // expires the key the instant it is written and makes the limiter permit
    // everything. It fails OPEN, so the constant is pinned here.
    expect(PostToConversation::WINDOW)->toBeInt()->toBeGreaterThan(0);
});

it('lets the author, the commissioner and an admin pull a post — and nobody else', function () {
    [$commissioner, $group] = pickemContest(ContestMode::Classic);

    $author = User::factory()->create();
    $stranger = User::factory()->create();
    $admin = User::factory()->create(['admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $author->id]);

    $action = app(DeleteConversationPost::class);

    $make = fn () => ConversationPost::factory()->create([
        'topic_type' => 'group', 'topic_id' => $group->id, 'user_id' => $author->id,
    ]);

    expect($action->mayModerate($author, $make()))->toBeTrue()
        ->and($action->mayModerate($commissioner, $make()))->toBeTrue()
        ->and($action->mayModerate($admin, $make()))->toBeTrue()
        ->and($action->mayModerate($stranger, $make()))->toBeFalse();

    expect(fn () => $action->handle($stranger, $make()))
        ->toThrow(CannotModeratePost::class);

    $post = $make();
    $action->handle($commissioner, $post);

    expect(ConversationPost::query()->whereKey($post->id)->exists())->toBeFalse();
});

it('does not let a commissioner moderate outside their own group', function () {
    /*
     * A game conversation has no commissioner — the league-wide surfaces are
     * moderated by the house, so the group role must not reach them.
     *
     * Built by hand rather than from pickemContest(), whose commissioner is
     * an ADMIN while the phase sits behind the flag: that user may moderate
     * anywhere by a different rule, and this test would pass for the wrong
     * reason without ever exercising the scope check.
     */
    $group = Group::factory()->create();
    $commissioner = User::factory()->create(['admin' => false]);
    GroupMember::factory()->commissioner()->create([
        'group_id' => $group->id, 'user_id' => $commissioner->id,
    ]);

    $ownRoom = ConversationPost::factory()->create([
        'topic_type' => 'group', 'topic_id' => $group->id,
    ]);

    $elsewhere = ConversationPost::factory()->create([
        'topic_type' => 'game', 'topic_id' => talkGame()->id,
    ]);

    $action = app(DeleteConversationPost::class);

    expect($action->mayModerate($commissioner, $ownRoom))->toBeTrue()
        ->and($action->mayModerate($commissioner, $elsewhere))->toBeFalse();

    // And it is not the role that failed to load — it is the scope.
    $otherRoom = Group::factory()->create();
    $notTheirs = ConversationPost::factory()->create([
        'topic_type' => 'group', 'topic_id' => $otherRoom->id,
    ]);

    expect($action->mayModerate($commissioner, $notTheirs))->toBeFalse();
});

it('renders a thread oldest-first with the newest post at the bottom', function () {
    $game = talkGame();
    $author = User::factory()->create(['handle' => 'vol_fan']);

    foreach (['First take.', 'Second take.', 'Third take.'] as $body) {
        ConversationPost::factory()->create([
            'topic_type' => 'game', 'topic_id' => $game->id, 'user_id' => $author->id, 'body' => $body,
        ]);
    }

    $html = Livewire::actingAs($author)->test('conversation', ['topic' => $game])->html();

    expect(strpos($html, 'First take.'))->toBeLessThan(strpos($html, 'Third take.'))
        ->and($html)->toContain('@vol_fan');
});

it('keeps the NEWEST post visible when the thread is longer than the window', function () {
    /*
     * The probe row: the window fetches shown + 1 rows newest-first purely to
     * answer "is there older?". Reversing BEFORE trimming would drop the
     * newest post off the bottom of a full thread — the reader would post and
     * watch their own line not appear. Trim, then reverse.
     */
    $game = talkGame();
    $author = User::factory()->create();

    foreach (range(1, 30) as $i) {
        ConversationPost::factory()->create([
            'topic_type' => 'game', 'topic_id' => $game->id, 'user_id' => $author->id, 'body' => "Take {$i}.",
        ]);
    }

    $component = Livewire::actingAs($author)->test('conversation', ['topic' => $game]);

    $component->assertSee('Take 30.')
        ->assertSee('Take 6.')
        // Older than the 25-row window, and reachable only by asking.
        ->assertDontSee('Take 5.')
        ->assertSee('Show older');

    $component->call('older')
        ->assertSee('Take 1.')
        ->assertSee('Take 30.');
});

it('posts through the component and clears the box', function () {
    $game = talkGame();
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('conversation', ['topic' => $game])
        ->set('body', 'The clock management was a hate crime against the sport.')
        ->call('post')
        ->assertSet('body', '')
        ->assertSee('hate crime against the sport');

    expect(ConversationPost::query()->where('topic_type', 'game')->count())->toBe(1);
});

it('shows a guest the thread and a way in, but no composer', function () {
    $game = talkGame();
    ConversationPost::factory()->create([
        'topic_type' => 'game', 'topic_id' => $game->id, 'body' => 'Readable by anyone.',
    ]);

    // Reading is open — the gate is on the write and lives in the Action.
    Livewire::test('conversation', ['topic' => $game])
        ->assertSee('Readable by anyone.')
        ->assertSee(Voice::line('talk.guest'))
        ->assertDontSee('Say something');
});

it('tells an unverified reader why the post did not land', function () {
    $game = talkGame();
    $user = User::factory()->unverified()->create();

    Livewire::actingAs($user)->test('conversation', ['topic' => $game])
        ->set('body', 'Let me in.')
        ->call('post')
        ->assertSee(Voice::line('talk.verify_first', for: $user));

    expect(ConversationPost::query()->count())->toBe(0);
});

it('raises the claim form for a reader with no handle, and takes the claim', function () {
    $game = talkGame();
    $user = User::factory()->create(['handle' => null]);

    Livewire::actingAs($user)->test('conversation', ['topic' => $game])
        ->assertSee(Voice::line('picks.claim.heading'))
        ->assertSee(Voice::line('talk.claim.body', for: $user))
        ->set('handle', 'neyland_ghost')
        ->call('claim')
        ->assertSee('@neyland_ghost');

    expect($user->fresh()->handle)->toBe('neyland_ghost');
});

it('tells an outsider that a group room costs a membership', function () {
    $group = Group::factory()->create();
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)->test('conversation', ['topic' => $group])
        ->set('body', 'Sliding in.')
        ->call('post')
        ->assertSee(Voice::line('talk.not_member', for: $outsider));

    expect(ConversationPost::query()->count())->toBe(0);
});

it('draws the delete control only for someone who may actually use it', function () {
    $game = talkGame();
    $author = User::factory()->create();
    $stranger = User::factory()->create();

    ConversationPost::factory()->create([
        'topic_type' => 'game', 'topic_id' => $game->id, 'user_id' => $author->id, 'body' => 'Mine.',
    ]);

    Livewire::actingAs($author)->test('conversation', ['topic' => $game])
        ->assertSee('Delete post');

    Livewire::actingAs($stranger)->test('conversation', ['topic' => $game])
        ->assertSee('Mine.')
        ->assertDontSee('Delete post');
});

it('deletes rather than edits, and the row is really gone', function () {
    $game = talkGame();
    $author = User::factory()->create();

    $post = ConversationPost::factory()->create([
        'topic_type' => 'game', 'topic_id' => $game->id, 'user_id' => $author->id, 'body' => 'Regrettable.',
    ]);

    Livewire::actingAs($author)->test('conversation', ['topic' => $game])
        ->call('deletePost', $post->id)
        ->assertDontSee('Regrettable.');

    // No soft delete: the reader query would carry a whereNull forever for an
    // audit nobody asked for.
    expect(ConversationPost::query()->whereKey($post->id)->exists())->toBeFalse();
});

it('mounts the conversation on all three host screens', function () {
    /*
     * A SOURCE sweep, because no feature test can catch a host that quietly
     * drops the mount — and because the alternative is standing up three full
     * screen fixtures to assert one tag. Each host must pass its own topic.
     */
    $hosts = [
        'game' => '<livewire:conversation :topic="$game"',
        'team' => '<livewire:conversation :topic="$team"',
        'group' => '<livewire:conversation :topic="$group"',
    ];

    foreach ($hosts as $screen => $tag) {
        $source = file_get_contents(resource_path("views/livewire/{$screen}.blade.php"));

        expect($source)->toContain($tag);
    }
});

it('embeds lazily inside the clubhouse, and hydrates to the real thread', function () {
    /*
     * `lazy` since the loading pass: the thread is the FOOT of the page,
     * so first paint carries the skeleton and the x-intersect hydrator,
     * and the posts' queries belong to the scroll that reaches them. The
     * page pin is the SHELL; the hydrated half is proven on the component
     * itself, which is what the intersect mounts.
     */
    [$commissioner, $group] = pickemContest(ContestMode::Classic);

    ConversationPost::factory()->create([
        'topic_type' => 'group', 'topic_id' => $group->id, 'body' => 'Slate is soft this week.',
    ]);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSeeHtml('wire:name="conversation"')
        ->assertSeeHtml('x-intersect')
        ->assertSee('animate-pulse');

    Livewire::actingAs($commissioner)->test('conversation', ['topic' => $group->fresh()])
        ->assertSee('Slate is soft this week.')
        ->assertSee(Voice::line('talk.subheading.group', for: $commissioner));
});

it('loads the author columns the row renders, so no host 500s on a lazy read', function () {
    /*
     * Model::preventLazyLoading() is on in testing but the PER-INSTANCE flag
     * on a model retrieved during a test is false, so an unloaded relation
     * resolves silently here and only throws in production. The guard is a
     * source sweep: the row prints the handle with `name` (first + last) as
     * its fallback, so the constrained load must carry all three.
     */
    $source = file_get_contents(resource_path('views/livewire/conversation.blade.php'));

    expect($source)->toContain("with('user:id,first_name,last_name,handle')");

    foreach (['handle', 'name'] as $read) {
        expect($source)->toContain('$post->user->'.$read);
    }
});

it('speaks every register on the conversation family', function () {
    /*
     * Voice is a product requirement on a LOUD surface, and Pick'em, Groups
     * and Conversations are LOUD wherever they appear — including on Game and
     * Team, whose facts above the rule stay pure. A key defining only pg13 is
     * the failure this catches.
     */
    $lines = (new ReflectionClass(Voice::class))->getConstant('LINES');

    $keys = array_keys(array_filter(
        $lines,
        fn (string $key) => str_starts_with($key, 'talk.'),
        ARRAY_FILTER_USE_KEY,
    ));

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        foreach (['pg', 'pg13', 'r'] as $register) {
            expect($lines[$key])->toHaveKey($register);
        }

        $pg = Voice::line($key, ['handle' => 'x', 'seconds' => 5], for: User::factory()->make(['content_rating' => ContentRating::Pg]));
        $r = Voice::line($key, ['handle' => 'x', 'seconds' => 5], for: User::factory()->make(['content_rating' => ContentRating::R]));

        expect($pg)->not->toBe('')->and($r)->not->toBe('');
    }
});

it('never names a school in the conversation copy', function () {
    // The Georgia sweep: the pilot audience is Tennessee alumni, and a
    // hardcoded example school is somebody's rival. A conversation line has no
    // reason to name one at all.
    $lines = (new ReflectionClass(Voice::class))->getConstant('LINES');

    foreach ($lines as $key => $variants) {
        if (! str_starts_with($key, 'talk.')) {
            continue;
        }

        foreach ($variants as $register => $line) {
            expect($line)->not->toMatch('/georgia/i');
        }
    }
});
