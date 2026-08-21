<?php

namespace App\Actions;

use App\Enums\ContestMode;
use App\Exceptions\ModeChangeBlocked;
use App\Exceptions\NotGroupCommissioner;
use App\Models\Contest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\User;
use App\Notifications\GroupModeChanged;
use App\Services\CfbCalendar;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * The ONE mode pivot a group gets per season — deliberate, guarded, and
 * announced.
 *
 * Guards, in order of who they protect: the commissioner seat (authority),
 * the once-per-season stamp (the league's own rule — `mode_changed_at`
 * null is the guard, the settled_at grammar), and the in-flight window
 * (the engine resolves mode AT GRADE TIME, so a published-unsettled week
 * must finish under the rules it was picked under — the pivot window is
 * settle-to-publish).
 *
 * A coming week's DRAFT is reset for refill rather than kept: it was
 * built to the old mode's size and tiers, and half a slate in the wrong
 * shape is nobody's week. Every member except the actor gets the note —
 * a mode change the group hears about from the standings is a betrayal.
 */
class ChangeGroupMode
{
    public function __construct(private CfbCalendar $calendar) {}

    /**
     * @throws NotGroupCommissioner
     * @throws ModeChangeBlocked
     * @throws InvalidArgumentException
     */
    public function handle(User $actor, Group $group, ContestMode $mode): Contest
    {
        $runsIt = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $actor->id)
            ->where('role', GroupMember::COMMISSIONER)
            ->exists();

        if (! $runsIt) {
            throw new NotGroupCommissioner;
        }

        $contest = $group->contests()
            ->where('season_year', $this->calendar->currentYear())
            ->first();

        if ($contest === null) {
            throw new InvalidArgumentException("Group {$group->id} has no contest this season.");
        }

        if (! $mode->available()) {
            throw new InvalidArgumentException("The {$mode->value} mode is not available to field.");
        }

        if ($mode === $contest->mode) {
            throw new InvalidArgumentException("Group {$group->id} already plays {$mode->value}.");
        }

        if ($contest->mode_changed_at !== null) {
            throw ModeChangeBlocked::alreadyUsed();
        }

        if ($contest->slates()->whereIn('status', [Slate::PUBLISHED, Slate::PRELIM])->exists()) {
            throw ModeChangeBlocked::slateInFlight();
        }

        $contest->update([
            'mode' => $mode,
            'mode_changed_at' => now(),
        ]);

        // Reset any draft for refill: clear the tiebreaker pointer FIRST —
        // it references a slate_games row about to be deleted (the FK
        // would null it anyway; being explicit keeps the intent readable).
        foreach ($contest->slates()->where('status', Slate::DRAFT)->get() as $draft) {
            $draft->update([
                'tiebreaker_slate_game_id' => null,
                'tiebreaker_metric' => null,
                'tiebreaker_team_id' => null,
            ]);
            $draft->games()->delete();
        }

        $told = $group->members()->where('users.id', '!=', $actor->id)->get();
        Notification::send($told, new GroupModeChanged($group, $mode));

        return $contest;
    }
}
