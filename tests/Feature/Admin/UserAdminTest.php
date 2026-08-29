<?php

use App\Actions\DeleteUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\FollowedTeamsRelationManager;
use App\Filament\Resources\Users\RelationManagers\GroupsRelationManager;
use App\Filament\Resources\Users\RelationManagers\PicksRelationManager;
use App\Filament\Resources\Users\RelationManagers\WalletEntriesRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Users\Widgets\UserStats;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\Team;
use App\Models\User;
use App\Models\WalletEntry;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The flagship resource, and the template every other FULL resource copies.
 *
 * Two model facts decide most of what is asserted here: `admin` is outside
 * `#[Fillable]` on purpose, so the toggle must ride forceFill and mass
 * assignment must stay blocked; and `name` is an ACCESSOR, so every sort and
 * search has to address `first_name`/`last_name` or MySQL answers 1054.
 */

beforeEach(function () {
    $this->admin = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Admin']);
    $this->admin->forceFill(['admin' => true])->save();
});

describe('the list', function () {
    it('searches the real columns behind the name accessor', function () {
        // `name` is assembled from two columns and does not exist in MySQL.
        // A bare ->searchable() on it is a 1054 at the moment somebody types.
        $target = User::factory()->create(['first_name' => 'Peyton', 'last_name' => 'Manning']);
        $other = User::factory()->create(['first_name' => 'Josh', 'last_name' => 'Heupel']);

        Livewire::actingAs($this->admin)
            ->test(ListUsers::class)
            ->searchTable('Manning')
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);
    });

    it('sorts by last name, which is the column that exists', function () {
        $zebra = User::factory()->create(['first_name' => 'Aaron', 'last_name' => 'Zebra']);
        $apple = User::factory()->create(['first_name' => 'Zoe', 'last_name' => 'Apple']);

        Livewire::actingAs($this->admin)
            ->test(ListUsers::class)
            ->sortTable('name')
            ->assertCanSeeTableRecords([$apple, $zebra], inOrder: true);
    });

    it('filters on the nullable stamps, not on booleans that do not exist', function () {
        $verified = User::factory()->create(['email_verified_at' => now()]);
        $unverified = User::factory()->unverified()->create();

        Livewire::actingAs($this->admin)
            ->test(ListUsers::class)
            ->filterTable('email_verified_at', true)
            ->assertCanSeeTableRecords([$verified])
            ->assertCanNotSeeTableRecords([$unverified])
            ->filterTable('email_verified_at', false)
            ->assertCanSeeTableRecords([$unverified])
            ->assertCanNotSeeTableRecords([$verified]);
    });

    it('offers no way to create an account by hand', function () {
        // Registration is the only door: a hand-made row skips the welcome
        // mail, the handle rules and the whole onboarding moment.
        expect(UserResource::getPages())->not->toHaveKey('create');
    });

    it('offers no bulk delete', function () {
        Livewire::actingAs($this->admin)
            ->test(ListUsers::class)
            ->assertTableBulkActionDoesNotExist('delete');
    });
});

describe('the record view', function () {
    it('renders the heading, the badges and every tab', function () {
        $user = User::factory()->create([
            'first_name' => 'Peyton',
            'last_name' => 'Manning',
            'handle' => 'sheriff',
            'email' => 'peyton@example.com',
            'onboarded_at' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $user->getKey()])
            ->assertOk()
            // The shared heading partial: name, badges, icon'd meta row.
            ->assertSee('Peyton Manning')
            ->assertSee('sheriff')
            ->assertSee('Verified')
            // ...and every tab's content, which is the half a page-load
            // assertion would otherwise never reach.
            ->assertSee('Profile')
            ->assertSee('Wallet & activity')
            ->assertSee('Notifications')
            ->assertSee('Lifecycle');
    });

    it('warns about the prune clock only while the account is unverified', function () {
        $unverified = User::factory()->unverified()->create(['verification_reminded_at' => now()]);
        $verified = User::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $unverified->getKey()])
            ->assertSee('Prune clock');

        // A prune warning on a verified account is noise that trains people
        // to ignore the one that matters.
        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $verified->getKey()])
            ->assertDontSee('Prune clock');
    });

    it('says an unwarned account is not yet collectable', function () {
        // prunable() requires verification_reminded_at, so an account nobody
        // warned is genuinely safe however old it is — the copy must not
        // promise a deletion that will not happen.
        $user = User::factory()->unverified()->create(['verification_reminded_at' => null]);
        $user->forceFill(['created_at' => now()->subMonth()])->save();

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $user->fresh()->getKey()])
            ->assertSee('not yet warned');
    });

    it('renders a pick record, and says so when nothing is graded', function () {
        $user = User::factory()->create();

        expect(UserResource::pickRecord($user))->toBe('Nothing graded yet');

        // picks carries a (slate_game_id, user_id) unique — one call per game
        // per person is the model, so three results need three games.
        [$season, $week] = pickemSeasonWeek();
        $slate = Slate::factory()->create(['week_id' => $week->id]);

        $games = collect(range(1, 3))->map(fn (): SlateGame => SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => pickemGame($season, $week)->id,
        ]));

        Pick::factory()->won()->create(['user_id' => $user->id, 'slate_game_id' => $games[0]->id]);
        Pick::factory()->won()->create(['user_id' => $user->id, 'slate_game_id' => $games[1]->id]);
        Pick::factory()->lost()->create(['user_id' => $user->id, 'slate_game_id' => $games[2]->id]);

        expect(UserResource::pickRecord($user))->toBe('2-1-0');
    });
});

describe('the KPI widget', function () {
    it('reads the wallet and the groups this person runs', function () {
        $user = User::factory()->create();
        WalletEntry::factory()->create(['user_id' => $user->id, 'xp' => 125, 'lattes' => 2]);

        $group = Group::factory()->create();
        $group->members()->attach($user->id, ['role' => GroupMember::COMMISSIONER]);

        Livewire::actingAs($this->admin)
            ->test(UserStats::class, ['record' => $user])
            ->assertOk()
            ->assertSee('125')
            ->assertSee('runs 1 of them');
    });

    it('never prints a win rate over nothing graded', function () {
        // An ungraded season is not 0% — it is no answer yet.
        $user = User::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(UserStats::class, ['record' => $user])
            ->assertOk()
            ->assertSee('nothing graded yet')
            ->assertDontSee('% of');
    });
});

describe('verifying by hand', function () {
    it('fires Verified rather than writing the column', function () {
        // markEmailAsVerified() is the doorway: the listener on Verified is
        // what pays the 100 XP and the Beast Latte. Writing the column
        // directly marks them verified and pays nothing.
        Event::fake([Verified::class]);

        $user = User::factory()->unverified()->create();

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $user->getKey()])
            ->callAction('verifyEmail');

        Event::assertDispatched(Verified::class);
        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('pays the verification reward exactly once, however many times it is pressed', function () {
        // The grant is keyed, and the (user_id, key) unique index is what
        // makes the second press a zero-row no-op rather than a double payout.
        $user = User::factory()->unverified()->create();

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $user->getKey()])
            ->callAction('verifyEmail');

        // Press it again on an account that verified once before. `refresh()`
        // first, or the stale instance still holds a null original and the
        // write below is not dirty — the reset would silently not happen and
        // the test would pass for the wrong reason.
        $user->refresh()->forceFill(['email_verified_at' => null])->save();

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $user->fresh()->getKey()])
            ->callAction('verifyEmail');

        expect(WalletEntry::query()
            ->where('user_id', $user->id)
            ->where('reason', 'email-verified')
            ->count())->toBe(1);
    });

    it('stops offering to verify an account that already is', function () {
        $user = User::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $user->getKey()])
            ->assertActionHidden('verifyEmail');
    });
});

describe('the admin toggle', function () {
    it('flips through the action, which forceFills a column mass assignment cannot reach', function () {
        $user = User::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $user->getKey()])
            ->callAction('toggleAdmin');

        expect($user->fresh()->isAdmin())->toBeTrue();

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $user->fresh()->getKey()])
            ->callAction('toggleAdmin');

        expect($user->fresh()->isAdmin())->toBeFalse();
    });

    it('leaves mass assignment blocked, which is the whole reason the action exists', function () {
        // `admin` is outside #[Fillable] deliberately — it is a privilege
        // escalation vector the moment it reaches a request-shaped write. With
        // preventSilentlyDiscardingAttributes on, a fill() does not quietly
        // drop it, it THROWS, which is the loudest possible version of this
        // guarantee. If this test ever stops throwing, the column became
        // fillable and every mass-assignment path is a way to make an admin.
        $user = User::factory()->create();

        expect(fn () => $user->fill(['admin' => true]))
            ->toThrow(MassAssignmentException::class);

        expect($user->fresh()->isAdmin())->toBeFalse();
    });

    it('never offers to demote yourself', function () {
        // An admin who revokes their own admin loses the panel and cannot get
        // back in through it.
        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $this->admin->getKey()])
            ->assertActionHidden('toggleAdmin');
    });
});

describe('deleting', function () {
    it('takes everything that only existed because of the account', function () {
        [$season, $week] = pickemSeasonWeek();
        $user = User::factory()->create();

        $slate = Slate::factory()->create(['week_id' => $week->id]);
        $slateGame = SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => pickemGame($season, $week)->id,
        ]);

        Pick::factory()->create(['user_id' => $user->id, 'slate_game_id' => $slateGame->id]);
        SlateEntry::factory()->create(['user_id' => $user->id, 'slate_id' => $slate->id]);
        WalletEntry::factory()->create(['user_id' => $user->id]);
        Group::factory()->create()->members()->attach($user->id, ['role' => 'member']);
        Team::factory()->create()->followers()->attach($user->id, ['position' => 1]);

        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $user->getKey()])
            ->callAction('delete');

        expect(User::find($user->id))->toBeNull()
            ->and(Pick::where('user_id', $user->id)->count())->toBe(0)
            ->and(SlateEntry::where('user_id', $user->id)->count())->toBe(0)
            ->and(WalletEntry::where('user_id', $user->id)->count())->toBe(0)
            ->and(GroupMember::where('user_id', $user->id)->count())->toBe(0)
            // ...and the slate itself is untouched: a contest does not
            // disappear because somebody who played in it left.
            ->and(Slate::find($slate->id))->not->toBeNull();
    });

    it('never offers to delete yourself, and refuses if it is asked anyway', function () {
        Livewire::actingAs($this->admin)
            ->test(ViewUser::class, ['record' => $this->admin->getKey()])
            ->assertActionHidden('delete');

        // The Action refuses independently of the button being hidden — a
        // panel with no admins left is not recoverable from inside the panel.
        expect(app(DeleteUser::class)->handle($this->admin, $this->admin))->toBeFalse()
            ->and(User::find($this->admin->id))->not->toBeNull();
    });

    it('hand-deletes the morph rows that no foreign key covers', function () {
        // notifications and push_subscriptions are morphs with no FK, so they
        // orphan forever unless deleted by hand — and an orphaned push row
        // still counts toward the push_devices telemetry number.
        $user = User::factory()->create();

        $user->updatePushSubscription('https://example.com/endpoint', 'a-key', 'a-token');
        $user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => 'test',
            'data' => ['body' => 'anything'],
        ]);

        expect($user->pushSubscriptions()->count())->toBe(1)
            ->and($user->notifications()->count())->toBe(1);

        app(DeleteUser::class)->handle($this->admin, $user);

        expect(DB::table('push_subscriptions')->count())->toBe(0)
            ->and(DB::table('notifications')->count())->toBe(0);
    });
});

describe('the relation managers', function () {
    it('shows followed teams in the reader\'s own order, with no way to rearrange them', function () {
        // That order drives their Home swipe order and their scoreboard float
        // order. An admin dragging it silently rearranges somebody's home
        // screen, and position 1 is their favorite team.
        $user = User::factory()->create();
        $favorite = Team::factory()->create(['display_name' => 'Tennessee Volunteers']);
        $second = Team::factory()->create(['display_name' => 'Memphis Tigers']);

        $favorite->followers()->attach($user->id, ['position' => 1]);
        $second->followers()->attach($user->id, ['position' => 2]);

        Livewire::actingAs($this->admin)
            ->test(FollowedTeamsRelationManager::class, [
                'ownerRecord' => $user,
                'pageClass' => ViewUser::class,
            ])
            ->assertOk()
            ->assertSee('Tennessee Volunteers')
            ->assertSee('★ Favorite')
            ->assertTableActionDoesNotExist('delete');
    });

    it('badges the commissioner of a group', function () {
        $user = User::factory()->create();
        Group::factory()->create(['name' => 'The Vol Network'])
            ->members()->attach($user->id, ['role' => GroupMember::COMMISSIONER]);

        Livewire::actingAs($this->admin)
            ->test(GroupsRelationManager::class, [
                'ownerRecord' => $user,
                'pageClass' => ViewUser::class,
            ])
            ->assertOk()
            ->assertSee('The Vol Network')
            ->assertSee('Commissioner');
    });

    it('shows picks an ordinary reader could not see yet, which is the point', function () {
        /*
         * Pick::visibleTo() hides a pick until its game kicks off — the whole
         * integrity model for readers. This surface bypasses it on purpose:
         * the alternative is a support conversation about a missing pick that
         * the admin cannot see either.
         */
        [$season, $week] = pickemSeasonWeek();
        $user = User::factory()->create();
        $team = Team::factory()->create(['abbreviation' => 'TENN']);

        $game = pickemGame($season, $week, ['kickoff_at' => now()->addWeek()]);
        $slateGame = SlateGame::factory()->create([
            'slate_id' => Slate::factory()->create(['week_id' => $week->id])->id,
            'game_id' => $game->id,
        ]);

        Pick::factory()->create([
            'user_id' => $user->id,
            'slate_game_id' => $slateGame->id,
            'picked_team_id' => $team->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(PicksRelationManager::class, [
                'ownerRecord' => $user,
                'pageClass' => ViewUser::class,
            ])
            ->assertOk()
            ->assertSee('TENN');
    });

    it('prints a backfired Lock as the real negative it is', function () {
        // picks.points is SIGNED for exactly this: the Woodshed Lock pays +6
        // right and −4 wrong, and printing that as 0 was a shipped bug.
        [$season, $week] = pickemSeasonWeek();
        $user = User::factory()->create();

        $slateGame = SlateGame::factory()->create([
            'slate_id' => Slate::factory()->create(['week_id' => $week->id])->id,
            'game_id' => pickemGame($season, $week)->id,
        ]);

        Pick::factory()->locked()->create([
            'user_id' => $user->id,
            'slate_game_id' => $slateGame->id,
            'result' => Pick::LOSS,
            'points' => -4,
        ]);

        Livewire::actingAs($this->admin)
            ->test(PicksRelationManager::class, [
                'ownerRecord' => $user,
                'pageClass' => ViewUser::class,
            ])
            ->assertOk()
            ->assertSee('-4');
    });

    it('grants through the Action and never edits the ledger', function () {
        // Totals are SUMs over these rows — there is no balance column — so an
        // edit or a delete would move a number somebody already saw.
        $user = User::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(WalletEntriesRelationManager::class, [
                'ownerRecord' => $user,
                'pageClass' => ViewUser::class,
            ])
            ->callAction(TestAction::make('grant')->table(), [
                'xp' => 50,
                'lattes' => 1,
                'reason' => 'apology',
            ])
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');

        expect($user->fresh()->walletTotals())->toBe(['xp' => 50, 'lattes' => 1])
            // No key: a hand grant is a one-off, and a keyed one would no-op
            // the second time an admin meant to give it twice.
            ->and(WalletEntry::where('user_id', $user->id)->sole()->key)->toBeNull();
    });
});

describe('the edit form', function () {
    it('saves the fillable columns and carries no admin field at all', function () {
        $user = User::factory()->create(['first_name' => 'Peyton', 'last_name' => 'Manning']);

        Livewire::actingAs($this->admin)
            ->test(EditUser::class, ['record' => $user->getKey()])
            ->assertOk()
            ->assertFormFieldExists('first_name')
            ->assertFormFieldDoesNotExist('admin')
            ->fillForm(['first_name' => 'Peyton W.'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($user->fresh()->first_name)->toBe('Peyton W.');
    });
});
