<?php

use App\Filament\Pages\PickemDashboard;
use App\Filament\Widgets\Analytics\LatePickShare;
use App\Filament\Widgets\Analytics\ParticipationBySlate;
use App\Filament\Widgets\Analytics\PickemStats;
use App\Filament\Widgets\Analytics\ReminderLift;
use App\Models\Contest;
use App\Models\Game;
use App\Models\Group;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\User;
use App\Support\Cadence;
use App\Support\LiveState;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Pick'em — one Saturday at a time.
 *
 * The rule this whole page rides on: ANY pick'em audience roots in
 * `group_members`, never in `slate_entries` or `picks`. An entry row is
 * created lazily on a member's first pick, so somebody who has picked nothing
 * has no entry and no pick rows — and that person is exactly who every number
 * here exists to find. The obvious implementation counts only the people who
 * already played, silently, while looking correct.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    $this->travelTo('2026-09-05 18:00:00');
});

/** A slate on the current Saturday with one real kickoff. */
function saturdaySlate(?Group $group = null, array $attributes = []): Slate
{
    $group ??= Group::factory()->create();
    $contest = Contest::factory()->create(['group_id' => $group->id, 'season_year' => 2026]);

    $slate = Slate::factory()->create([
        'contest_id' => $contest->id,
        'saturday' => Cadence::currentSaturday()->toDateString(),
        ...$attributes,
    ]);

    SlateGame::factory()->create([
        'slate_id' => $slate->id,
        'game_id' => Game::factory()->create([
            'kickoff_at' => Cadence::currentSaturday()->setTime(16, 0),
        ])->id,
    ]);

    return $slate->fresh();
}

/** A member of a group, joined at a pinned moment. */
function pickemJoinedAt(Group $group, string $at): User
{
    $user = User::factory()->create();

    $group->memberships()->create(['user_id' => $user->id, 'role' => 'member'])
        ->forceFill(['created_at' => $at])->save();

    return $user;
}

describe('the page', function () {
    it('renders for an admin and 403s for everybody else', function () {
        $this->actingAs($this->admin)->get('/admin/pickem')->assertOk();

        $this->actingAs(User::factory()->create())->get('/admin/pickem')->assertForbidden();
    });

    it('defaults to the pick\'em Saturday, not to today', function () {
        /*
         * Sunday and Monday still belong to the Saturday just played, which is
         * what Cadence knows and a bare today() would get wrong two days in
         * seven.
         */
        Livewire::actingAs($this->admin)
            ->test(PickemDashboard::class)
            ->assertSet('filters.saturday', Cadence::currentSaturday()->toDateString());
    });

    it('always offers the current Saturday, even before its games are synced', function () {
        // A scheduled-but-unplayed week is a real state, and a filter whose
        // default is not among its own options renders empty.
        expect(array_keys(PickemDashboard::saturdays()))
            ->toContain(Cadence::currentSaturday()->toDateString());
    });
});

describe('participation', function () {
    it('excludes a member who joined after kickoff from the denominator', function () {
        /*
         * THE POINT OF COUNTING AT KICKOFF. Somebody who joined on Sunday
         * could not have entered on Saturday, and including them turns growth
         * into a participation problem — the room looks like it is losing
         * people every time it gains one.
         */
        $group = Group::factory()->create();
        $slate = saturdaySlate($group);

        foreach (range(1, 3) as $i) {
            pickemJoinedAt($group, '2026-08-01 00:00:00');
        }

        pickemJoinedAt($group, Cadence::currentSaturday()->addDays(2)->toDateTimeString());

        $chart = Livewire::actingAs($this->admin)
            ->test(ParticipationBySlate::class, ['pageFilters' => ['saturday' => $slate->saturday]]);

        $members = collect($chart->instance()->options['series'])
            ->firstWhere('name', 'Members at kickoff');

        expect($members['data'])->toBe([3]);
    });

    it('roots the denominator in memberships, not in entries', function () {
        /*
         * Two members, one of whom has never picked and therefore has NO
         * `slate_entries` row at all. A denominator rooted in entries would
         * report one member and 100% participation on a room where half the
         * people did not show up.
         */
        $group = Group::factory()->create();
        $slate = saturdaySlate($group);

        $played = pickemJoinedAt($group, '2026-08-01 00:00:00');
        pickemJoinedAt($group, '2026-08-01 00:00:00');

        SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $played->id]);

        $chart = Livewire::actingAs($this->admin)
            ->test(ParticipationBySlate::class, ['pageFilters' => ['saturday' => $slate->saturday]]);

        $series = collect($chart->instance()->options['series']);

        expect($series->firstWhere('name', 'Members at kickoff')['data'])->toBe([2])
            ->and($series->firstWhere('name', 'Entered')['data'])->toBe([1]);
    });
});

describe('the late-pick share', function () {
    it('counts a pick inside the last-call window and not one before it', function () {
        /*
         * The window is `Cadence::LAST_CALL_MINUTES` before first kickoff,
         * read from the constant rather than restated — the notification the
         * product actually sends is timed off the same number, and a chart
         * with its own ninety would silently stop describing it.
         *
         * `updated_at`, not `created_at`: changing your mind at 11:58 is a
         * late pick.
         */
        $slate = saturdaySlate();
        $kickoff = $slate->firstKickoff();

        $slateGame = $slate->games->first();

        // Inside the window, by one minute.
        Pick::factory()->create(['slate_game_id' => $slateGame->id])
            ->forceFill(['updated_at' => CarbonImmutable::parse($kickoff)->subMinutes(Cadence::LAST_CALL_MINUTES - 1)])
            ->save();

        // Outside it, by one minute.
        Pick::factory()->create(['slate_game_id' => $slateGame->id])
            ->forceFill(['updated_at' => CarbonImmutable::parse($kickoff)->subMinutes(Cadence::LAST_CALL_MINUTES + 1)])
            ->save();

        $state = app(LiveState::class)->build(Cadence::currentSaturday(), names: false);

        expect(collect($state['contests'])->firstWhere('slate_id', $slate->id)['late_share'])
            ->toBe(0.5);
    });

    it('reports no share at all for a slate nobody picked', function () {
        // Zero would say people picked early on a day nobody picked at all.
        $slate = saturdaySlate();

        $state = app(LiveState::class)->build(Cadence::currentSaturday(), names: false);

        expect(collect($state['contests'])->firstWhere('slate_id', $slate->id)['late_share'])
            ->toBeNull();
    });

    it('leaves a Saturday with no picks off the chart entirely', function () {
        saturdaySlate();

        expect(Livewire::actingAs($this->admin)->test(LatePickShare::class)
            ->instance()->options['series'][0]['data'])->toBe([]);
    });
});

describe('the reminder lift', function () {
    it('says "no reminder sent" rather than 0% when the wave never went out', function () {
        // A 0% would blame the reminder for a message nobody sent.
        saturdaySlate(attributes: ['picks_reminded_at' => null]);

        Livewire::actingAs($this->admin)
            ->test(ReminderLift::class)
            ->assertOk()
            ->assertSee('no reminder sent');
    });

    it('measures the lift over the members who had not entered at the wave', function () {
        /*
         * The denominator is who COULD have been moved — members at the moment
         * of the wave with no entry yet. Rooting it in `slate_entries` would
         * measure the lift only on people who had already played, which is the
         * implementation that looks correct and answers a different question.
         *
         * Four members at the wave, one of whom had already entered: three
         * could be moved, and two entered afterwards.
         */
        $group = Group::factory()->create();
        $reminded = Cadence::currentSaturday()->setTime(10, 0);

        $slate = saturdaySlate($group, ['picks_reminded_at' => $reminded]);

        $members = collect(range(1, 4))->map(fn () => pickemJoinedAt($group, '2026-08-01 00:00:00'));

        SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $members[0]->id])
            ->forceFill(['created_at' => $reminded->subHour()])->save();

        foreach ([$members[1], $members[2]] as $mover) {
            SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $mover->id])
                ->forceFill(['created_at' => $reminded->addHour()])->save();
        }

        $state = app(LiveState::class)->build(Cadence::currentSaturday(), names: false);

        // Two moved of the three who could be: 0.667.
        expect(collect($state['contests'])->firstWhere('slate_id', $slate->id)['reminder_lift'])
            ->toBe(0.667);
    });

    it('still refuses to name a group, machine skin on', function () {
        // No shape of this report names a room. The snapshot reads these rows
        // and the snapshot leaves the machine.
        $group = Group::factory()->create(['name' => 'Rocky Top Regulars']);
        saturdaySlate($group);

        $state = app(LiveState::class)->build(Cadence::currentSaturday(), names: false);

        expect(json_encode($state))->not->toContain('Rocky Top Regulars')
            ->and(collect($state['contests'])->first()['group'])->toBeNull();
    });
});

describe('the Saturday stats', function () {
    it('says no data rather than 0% when no slate was published', function () {
        Livewire::actingAs($this->admin)
            ->test(PickemStats::class)
            ->assertOk()
            ->assertSee('no data')
            ->assertSee('No slate published for this Saturday');
    });
});
