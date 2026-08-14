<?php

use App\Enums\ContestMode;
use App\Models\Group;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use App\Support\Voice;
use Livewire\Livewire;

/*
 * YOUR WEEKS — the settled record, newest first, each row placed in its
 * field ("3rd of 9") and linked back to the room it happened in.
 */

it('lists settled weeks with place, points and the W', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $group->update(['name' => 'The Corner Bar']);
    [, $week] = pickemSeasonWeek();

    $slate = Slate::factory()->create([
        'contest_id' => $contest->id,
        'week_id' => $week->id,
        'status' => Slate::SETTLED,
        'settled_at' => now()->subDay(),
    ]);

    SlateEntry::factory()->create([
        'slate_id' => $slate->id, 'user_id' => $commissioner->id,
        'final_points' => 6, 'won' => false,
    ]);
    // Two ahead of me: I finished 3rd of 3.
    foreach ([9, 8] as $points) {
        SlateEntry::factory()->create([
            'slate_id' => $slate->id,
            'user_id' => User::factory()->create()->id,
            'final_points' => $points,
        ]);
    }

    Livewire::actingAs($commissioner)->test('pickem-history')
        ->assertSee('Week 1')
        ->assertSee('The Corner Bar')
        ->assertSee('Shotgun')
        ->assertSee('3rd of 3')
        ->assertSee('6 pts')
        ->assertSee('1 week')
        ->assertSee('0 wins');
});

it('links a room row back to its /contests address, and badges the W', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    [, $week] = pickemSeasonWeek();
    $group->update(['kind' => Group::KIND_LOBBY, 'week_id' => $week->id, 'member_cap' => 20]);

    $slate = Slate::factory()->create([
        'contest_id' => $contest->id,
        'week_id' => $week->id,
        'status' => Slate::SETTLED,
        'settled_at' => now(),
    ]);
    SlateEntry::factory()->create([
        'slate_id' => $slate->id, 'user_id' => $commissioner->id,
        'final_points' => 11, 'won' => true,
    ]);

    Livewire::actingAs($commissioner)->test('pickem-history')
        ->assertSee('Winner')
        ->assertSee(route('pickem.room', $group), escape: false);
});

it('keeps unsettled weeks out, and answers an empty record honestly', function () {
    [$commissioner, , $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftBoard($contest);
    SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $commissioner->id]);

    Livewire::actingAs($commissioner)->test('pickem-history')
        ->assertDontSee('Week 1')
        ->assertSee(Voice::line('history.empty'));
});

it('stays behind the flag', function () {
    $this->actingAs(User::factory()->create())->get(route('pickem.history'))->assertBadRequest();
    $this->actingAs(pickemAdmin())->get(route('pickem.history'))->assertOk();
});
