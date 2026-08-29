<?php

use App\Filament\Resources\ConversationPosts\Pages\ManageConversationPosts;
use App\Models\ConversationPost;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * Moderation across all three conversation kinds.
 *
 * Every delete rides `DeleteConversationPost` — never `$post->delete()` —
 * because that Action owns the rule about who may moderate what, and a panel
 * that answered it separately would be a second answer to drift from.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    [$this->season, $this->week] = pickemSeasonWeek();
});

describe('the table', function () {
    it('names the topic for each morph alias', function () {
        /*
         * A conversation IS its (topic_type, topic_id) pair — there is no
         * parent table. The three types are morph-map ALIASES, and
         * Relation::enforceMorphMap makes an unmapped model throw on write, so
         * these three are the whole vocabulary.
         */
        $game = pickemGame($this->season, $this->week, ['short_name' => 'TENN @ BAMA']);
        $team = Team::factory()->create(['display_name' => 'Tennessee Volunteers']);
        $group = Group::factory()->create(['name' => 'The Vol Network']);

        foreach ([$game, $team, $group] as $topic) {
            ConversationPost::factory()->create([
                'topic_type' => $topic->getMorphClass(),
                'topic_id' => $topic->getKey(),
                'user_id' => User::factory()->create()->id,
            ]);
        }

        Livewire::actingAs($this->admin)
            ->test(ManageConversationPosts::class)
            ->assertOk()
            ->assertSee('TENN @ BAMA')
            ->assertSee('Tennessee Volunteers')
            ->assertSee('The Vol Network');
    });

    it('filters to one kind of conversation', function () {
        $team = Team::factory()->create();
        $group = Group::factory()->create();

        $onTeam = ConversationPost::factory()->create([
            'topic_type' => $team->getMorphClass(),
            'topic_id' => $team->getKey(),
            'body' => 'Said on a team page',
        ]);
        $onGroup = ConversationPost::factory()->create([
            'topic_type' => $group->getMorphClass(),
            'topic_id' => $group->getKey(),
            'body' => 'Said in a group',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ManageConversationPosts::class)
            ->filterTable('topic_type', 'team')
            ->assertCanSeeTableRecords([$onTeam])
            ->assertCanNotSeeTableRecords([$onGroup]);
    });

    it('offers no way to edit a post', function () {
        // `conversation_posts` has no `updated_at` on purpose: an editable
        // post lets a quote be made to lie about what was said.
        $post = ConversationPost::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ManageConversationPosts::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionExists('delete');
    });
});

describe('deleting', function () {
    it('rides the Action, and the row really goes', function () {
        $post = ConversationPost::factory()->create(['body' => 'Something regretted']);

        Livewire::actingAs($this->admin)
            ->test(ManageConversationPosts::class)
            ->callAction(TestAction::make('delete')->table($post));

        // A real delete, not a soft one: a soft delete keeps the row for an
        // audit nobody asked for and leaves every reader query carrying a
        // whereNull forever.
        expect(ConversationPost::find($post->id))->toBeNull();
    });

    it('deletes several at once, each through the same Action', function () {
        $posts = ConversationPost::factory()->count(3)->create();

        Livewire::actingAs($this->admin)
            ->test(ManageConversationPosts::class)
            ->callTableBulkAction('delete', $posts);

        expect(ConversationPost::count())->toBe(0);
    });

    it('refuses a post the actor may not moderate, and says so', function () {
        /*
         * The guard lives in the Action: an author, a commissioner of the
         * group it was posted in, or an app admin. This actor is none of the
         * three — and the refusal must be visible, because a delete button
         * that appears to do nothing gets pressed again.
         */
        $group = Group::factory()->create();
        $post = ConversationPost::factory()->create([
            'topic_type' => $group->getMorphClass(),
            'topic_id' => $group->getKey(),
            'user_id' => User::factory()->create()->id,
        ]);

        $nonAdmin = User::factory()->create();

        Livewire::actingAs($nonAdmin)
            ->test(ManageConversationPosts::class)
            ->callAction(TestAction::make('delete')->table($post))
            ->assertNotified();

        expect(ConversationPost::find($post->id))->not->toBeNull();
    });

    it('lets a group commissioner pull a post from THEIR group only', function () {
        $theirs = Group::factory()->create();
        $notTheirs = Group::factory()->create();
        $commissioner = User::factory()->create();

        $theirs->members()->attach($commissioner->id, ['role' => GroupMember::COMMISSIONER]);

        $inTheirGroup = ConversationPost::factory()->create([
            'topic_type' => $theirs->getMorphClass(),
            'topic_id' => $theirs->getKey(),
            'user_id' => User::factory()->create()->id,
        ]);
        $elsewhere = ConversationPost::factory()->create([
            'topic_type' => $notTheirs->getMorphClass(),
            'topic_id' => $notTheirs->getKey(),
            'user_id' => User::factory()->create()->id,
        ]);

        Livewire::actingAs($commissioner)
            ->test(ManageConversationPosts::class)
            ->callAction(TestAction::make('delete')->table($inTheirGroup));

        Livewire::actingAs($commissioner)
            ->test(ManageConversationPosts::class)
            ->callAction(TestAction::make('delete')->table($elsewhere));

        expect(ConversationPost::find($inTheirGroup->id))->toBeNull()
            ->and(ConversationPost::find($elsewhere->id))->not->toBeNull();
    });
});
