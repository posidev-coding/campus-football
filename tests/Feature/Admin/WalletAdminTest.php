<?php

use App\Filament\Resources\WalletEntries\Pages\ManageWalletEntries;
use App\Models\User;
use App\Models\WalletEntry;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * The wallet ledger as an audit surface.
 *
 * Append-only, and that is structural rather than a policy: there is no
 * balance column anywhere in this app, so totals ARE these rows summed. An
 * edit here would move a number somebody already saw, with nothing left
 * recording that it moved.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();
});

it('renders the ledger with signed amounts and the person who earned them', function () {
    $user = User::factory()->create(['first_name' => 'Peyton', 'last_name' => 'Manning']);

    WalletEntry::factory()->create([
        'user_id' => $user->id,
        'xp' => 100,
        'credits' => 1,
        'reason' => 'email-verified',
        'key' => 'email-verified',
    ]);

    Livewire::actingAs($this->admin)
        ->test(ManageWalletEntries::class)
        ->assertOk()
        ->assertSee('Peyton Manning')
        ->assertSee('email-verified')
        ->assertSee('+100');
});

it('says a keyless row is REPEATABLE rather than leaving it blank', function () {
    // Null here is not missing data — a repeatable entry (a spend, a weekly
    // win) genuinely carries no key, and that is a different kind of row.
    $entry = WalletEntry::factory()->create(['key' => null]);

    Livewire::actingAs($this->admin)
        ->test(ManageWalletEntries::class)
        ->assertOk()
        ->assertTableColumnStateSet('key', null, $entry);
});

it('renders a spend as the negative it is', function () {
    WalletEntry::factory()->create(['xp' => -50, 'reason' => 'contest-entry']);

    Livewire::actingAs($this->admin)
        ->test(ManageWalletEntries::class)
        ->assertOk()
        ->assertSee('-50');
});

it('filters by reason', function () {
    $verified = WalletEntry::factory()->create(['reason' => 'email-verified']);
    $talk = WalletEntry::factory()->create(['reason' => 'talk']);

    Livewire::actingAs($this->admin)
        ->test(ManageWalletEntries::class)
        ->filterTable('reason', 'talk')
        ->assertCanSeeTableRecords([$talk])
        ->assertCanNotSeeTableRecords([$verified]);
});

it('grants through the Action, keyless, so a second grant is not a no-op', function () {
    // GrantWalletEntry is the one doorway every earn and spend uses — it is
    // where the idempotency rule lives, and a second writer is how that rule
    // gets forgotten. A hand grant passes NO key: a keyed one would silently
    // do nothing the second time an admin meant to give it.
    $user = User::factory()->create(['first_name' => 'Peyton', 'last_name' => 'Manning']);

    Livewire::actingAs($this->admin)
        ->test(ManageWalletEntries::class)
        ->callAction(TestAction::make('grant')->table(), [
            'user_id' => $user->id,
            'xp' => 50,
            'credits' => 1,
            'reason' => 'apology',
        ]);

    expect($user->fresh()->walletTotals())->toBe(['xp' => 50, 'credits' => 1])
        ->and(WalletEntry::where('user_id', $user->id)->sole()->key)->toBeNull();
});

it('offers no edit and no delete, ever', function () {
    // If either of these ever appears, the ledger stopped being append-only
    // and every total in the app became editable history.
    WalletEntry::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(ManageWalletEntries::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});
