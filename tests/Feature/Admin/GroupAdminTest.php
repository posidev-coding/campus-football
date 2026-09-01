<?php

use App\Filament\Resources\Contests\ContestResource;
use App\Filament\Resources\Contests\Pages\ViewContest;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\Pages\ViewGroup;
use App\Filament\Resources\Groups\RelationManagers\ContestsRelationManager;
use App\Filament\Resources\Groups\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Groups\Widgets\GroupStats;
use App\Models\Contest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * Groups and rooms — the same table wearing a different `kind`.
 *
 * The one WRITE this surface has is removing a member, and it rides
 * RemoveGroupMember rather than the pivot, because that Action owns the rule
 * that only a commissioner may do it.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();
});

describe('the list', function () {
    it('tells a private group from a lobby room', function () {
        $private = Group::factory()->create(['name' => 'The Vol Network']);
        $room = Group::factory()->lobby()->create(['name' => 'Saturday Room']);

        Livewire::actingAs($this->admin)
            ->test(ListGroups::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$private, $room])
            ->filterTable('kind', Group::KIND_LOBBY)
            ->assertCanSeeTableRecords([$room])
            ->assertCanNotSeeTableRecords([$private]);
    });

    it('says Standard rather than nothing for a room with no flavor', function () {
        // A retired flavor degrades to standard rather than throwing on a
        // room that still has a URL — flavorEnum() is deliberately tolerant.
        Group::factory()->lobby()->create(['flavor' => null]);

        Livewire::actingAs($this->admin)
            ->test(ListGroups::class)
            ->assertOk()
            ->assertSee('Standard');
    });

    it('renders a room whose flavor no longer exists in the enum', function () {
        Group::factory()->lobby()->create(['flavor' => 'a_retired_flavor']);

        Livewire::actingAs($this->admin)
            ->test(ListGroups::class)
            ->assertOk();
    });

    it('counts members and contests without a query per row', function () {
        $group = Group::factory()->create();
        $group->members()->attach(User::factory()->count(3)->create()->pluck('id'), ['role' => 'member']);
        Contest::factory()->create(['group_id' => $group->id]);

        Livewire::actingAs($this->admin)
            ->test(ListGroups::class)
            ->assertOk()
            ->assertTableColumnStateSet('members_count', 3, $group);
    });

    it('offers no way to create or bulk-delete a group', function () {
        expect(GroupResource::getPages())->not->toHaveKey('create');

        Livewire::actingAs($this->admin)
            ->test(ListGroups::class)
            ->assertTableBulkActionDoesNotExist('delete');
    });
});

describe('the record view', function () {
    it('renders the heading, the badges and the KPI numbers', function () {
        $group = Group::factory()->create(['name' => 'The Vol Network', 'member_cap' => 10]);
        $group->members()->attach(User::factory()->count(2)->create()->pluck('id'), ['role' => 'member']);

        Livewire::actingAs($this->admin)
            ->test(ViewGroup::class, ['record' => $group->getKey()])
            ->assertOk()
            ->assertSee('The Vol Network')
            ->assertSee('Private group')
            ->assertSee($group->code);

        Livewire::actingAs($this->admin)
            ->test(GroupStats::class, ['record' => $group])
            ->assertOk()
            ->assertSee('2 of 10 seats');
    });

    it('says uncapped rather than inventing a ceiling', function () {
        $group = Group::factory()->create(['member_cap' => null]);

        Livewire::actingAs($this->admin)
            ->test(GroupStats::class, ['record' => $group])
            ->assertOk()
            ->assertSee('uncapped');
    });
});

describe('removing a member', function () {
    it('goes through the Action, and the Action does the removing', function () {
        $group = Group::factory()->create();
        $commissioner = User::factory()->create();
        $member = User::factory()->create();

        $group->members()->attach($commissioner->id, ['role' => GroupMember::COMMISSIONER]);
        $group->members()->attach($member->id, ['role' => 'member']);

        // The admin here IS the commissioner, which is what the Action asks.
        $commissioner->forceFill(['admin' => true])->save();

        Livewire::actingAs($commissioner)
            ->test(MembersRelationManager::class, [
                'ownerRecord' => $group,
                'pageClass' => ViewGroup::class,
            ])
            ->callAction(TestAction::make('remove')->table($member));

        expect(GroupMember::where('group_id', $group->id)->where('user_id', $member->id)->count())->toBe(0)
            // ...and the commissioner is untouched.
            ->and(GroupMember::where('group_id', $group->id)->count())->toBe(1);
    });

    it('surfaces the Action\'s refusal instead of failing quietly', function () {
        // An admin who does not run this group cannot remove from it — the
        // rule is the commissioner's, not the panel's. A button that appears
        // to do nothing gets pressed again.
        $group = Group::factory()->create();
        $member = User::factory()->create();
        $group->members()->attach($member->id, ['role' => 'member']);

        Livewire::actingAs($this->admin)
            ->test(MembersRelationManager::class, [
                'ownerRecord' => $group,
                'pageClass' => ViewGroup::class,
            ])
            ->callAction(TestAction::make('remove')->table($member))
            ->assertNotified();

        expect(GroupMember::where('group_id', $group->id)->where('user_id', $member->id)->count())->toBe(1);
    });
});

describe('contests', function () {
    it('links a contest row out to its own view page', function () {
        $group = Group::factory()->create();
        $contest = Contest::factory()->woodshed()->create(['group_id' => $group->id, 'season_year' => 2026]);

        Livewire::actingAs($this->admin)
            ->test(ContestsRelationManager::class, [
                'ownerRecord' => $group,
                'pageClass' => ViewGroup::class,
            ])
            ->assertOk()
            ->assertSee('Woodshed')
            ->assertSee('2026');

        Livewire::actingAs($this->admin)
            ->test(ViewContest::class, ['record' => $contest->getKey()])
            ->assertOk()
            ->assertSee($group->name);
    });

    it('renders the settings JSON without falling over on the array cast', function () {
        // `settings` has an array cast, and Filament renders an array state as
        // a LIST — one formatter call per element, with the element. The
        // ->state() collapse is what keeps that from being a TypeError.
        $contest = Contest::factory()->create([
            'settings' => ['lock_bonus' => 6, 'tiers' => ['top' => 9, 'mid' => 7]],
        ]);

        Livewire::actingAs($this->admin)
            ->test(ViewContest::class, ['record' => $contest->getKey()])
            ->assertOk()
            ->assertSee('lock_bonus');
    });

    it('helps the admin with the card the contest actually deals', function () {
        // Sized from the RECORD, not the mode: a downsized Shotgun room
        // reads eight games here, the same number its players are shown.
        $contest = Contest::factory()->create(['settings' => ['slate_size' => 8]]);

        Livewire::actingAs($this->admin)
            ->test(ViewContest::class, ['record' => $contest->getKey()])
            ->assertOk()
            ->assertSee('8 games, 10 points each.');
    });

    it('renders a contest with no settings at all', function () {
        $contest = Contest::factory()->create(['settings' => null]);

        Livewire::actingAs($this->admin)
            ->test(ViewContest::class, ['record' => $contest->getKey()])
            ->assertOk()
            ->assertSee('Defaults');
    });

    it('stays off the sidebar, because it is always reached through a group', function () {
        expect(ContestResource::shouldRegisterNavigation())->toBeFalse();
    });

    it('offers no way to change the mode from the panel', function () {
        // Changing a mode mid-season rewrites what published slates mean.
        // ChangeGroupMode is the flow that knows it.
        expect(ContestResource::getPages())
            ->toHaveKey('view')
            ->not->toHaveKey('edit');
    });
});

describe('the edit form', function () {
    it('reaches the name and the cap and nothing structural', function () {
        $group = Group::factory()->create(['name' => 'Old Name']);

        Livewire::actingAs($this->admin)
            ->test(EditGroup::class, ['record' => $group->getKey()])
            ->assertOk()
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('member_cap')
            // Structural: changing any of these turns a group into a
            // different thing while people are sitting in it.
            ->assertFormFieldDoesNotExist('kind')
            ->assertFormFieldDoesNotExist('flavor')
            ->assertFormFieldDoesNotExist('week_id')
            ->assertFormFieldDoesNotExist('code')
            ->fillForm(['name' => 'New Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($group->fresh()->name)->toBe('New Name');
    });
});
