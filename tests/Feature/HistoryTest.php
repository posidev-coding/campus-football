<?php

use App\Enums\ContestMode;
use App\Models\Contest;
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
        ->assertSee('1 entry')
        ->assertSee('0 wins');
});

it('counts three rooms on one Saturday as one week and three entries', function () {
    /*
     * THE STRIP MUST AGREE WITH THE LIST. Public rooms are joined a
     * Saturday at a time and a reader can hold several at once, so the
     * row count and the week count are two different numbers — and the
     * chip was the row count wearing the word "weeks". Three rooms under
     * a single Week 1 heading read as "3 weeks", contradicting the one
     * heading directly beneath it.
     */
    $viewer = pickemAdmin();
    [, $week] = pickemSeasonWeek();

    foreach (['Hail Mary', 'Two-Minute Drill', 'Upset Alley'] as $i => $name) {
        $room = Group::factory()->create([
            'name' => $name, 'kind' => Group::KIND_LOBBY, 'week_id' => $week->id, 'member_cap' => 20,
        ]);
        $contest = Contest::factory()->create(['group_id' => $room->id, 'mode' => ContestMode::Classic]);

        // One ESPN week, one Saturday, three contests.
        $slate = Slate::factory()->create([
            'contest_id' => $contest->id,
            'week_id' => $week->id,
            'saturday' => '2026-09-05',
            'status' => Slate::SETTLED,
            'settled_at' => now()->subDay(),
        ]);

        SlateEntry::factory()->create([
            'slate_id' => $slate->id, 'user_id' => $viewer->id,
            'final_points' => 60 - $i * 20, 'won' => false,
        ]);
    }

    $screen = Livewire::actingAs($viewer)->test('pickem-history');

    // The chip IS the number of headings, by construction.
    expect($screen->instance()->weeks->count())->toBe(1)
        ->and($screen->instance()->summary['weeks'])->toBe(1)
        ->and($screen->instance()->summary['entries'])->toBe(3);

    $screen
        ->assertSee('1 week')
        ->assertSee('3 entries')
        ->assertDontSee('3 weeks')
        ->assertSee('best 60 pts')
        // ...and all three rooms are still listed under the one heading.
        ->assertSee('Hail Mary')
        ->assertSee('Two-Minute Drill')
        ->assertSee('Upset Alley');
});

it('splits the opening week into Week 0 and Week 1 headings', function () {
    [$commissioner, , $contest] = pickemContest(ContestMode::Classic);
    [, $week] = splitPickemWeek();

    // One settled entry on each of the split week's cards — same ESPN
    // week id, different Saturdays, and they must NOT share a heading.
    foreach (['2026-08-29', '2026-09-05'] as $i => $saturday) {
        $slate = Slate::factory()->create([
            'contest_id' => $contest->id,
            'week_id' => $week->id,
            'saturday' => $saturday,
            'status' => Slate::SETTLED,
            'settled_at' => now()->subDays(8 - $i * 7),
        ]);

        SlateEntry::factory()->create([
            'slate_id' => $slate->id, 'user_id' => $commissioner->id,
            'final_points' => 50, 'won' => false,
        ]);
    }

    Livewire::actingAs($commissioner)->test('pickem-history')
        ->assertSee('Week 0')
        ->assertSee('Week 1')
        ->assertSee('2 weeks');
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
    $slate = pickemDraftSlate($contest);
    SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $commissioner->id]);

    Livewire::actingAs($commissioner)->test('pickem-history')
        ->assertDontSee('Week 1')
        ->assertSee(Voice::line('history.empty'));
});

it('stays behind the flag', function () {
    $this->actingAs(User::factory()->create())->get(route('pickem.history'))->assertBadRequest();
    $this->actingAs(pickemAdmin())->get(route('pickem.history'))->assertOk();
});
