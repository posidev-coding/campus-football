<?php

namespace App\Actions;

use App\Enums\ContestMode;
use App\Exceptions\PickemParticipationGated;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\CfbCalendar;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Start a group: the group row, its creator seated as commissioner, and
 * ONE contest — the game this group plays all season, chosen at the door.
 * Changing it later is ChangeGroupMode's deliberate, announced, once-per-
 * season act, never a side effect.
 *
 * The invite code IS the invite: eight uppercase characters, generated
 * never chosen, unique by retry against the column's own index.
 */
class CreateGroup
{
    public function __construct(
        private CfbCalendar $calendar,
        private GrantWalletEntry $wallet,
    ) {}

    /**
     * @throws PickemParticipationGated when the creator is unverified
     * @throws InvalidArgumentException when the mode cannot be fielded yet
     */
    public function handle(User $creator, string $name, ContestMode $mode, string $kind = Group::KIND_PRIVATE): Group
    {
        if (! $creator->hasVerifiedEmail()) {
            throw new PickemParticipationGated;
        }

        // The Woodshed stays out of every door until its rules land — a
        // mode that errors is a promise the app must not make.
        if (! $mode->available()) {
            throw new InvalidArgumentException("The {$mode->value} mode is not available to field.");
        }

        $group = Group::create([
            'name' => $name,
            'code' => $this->uniqueCode(),
            'kind' => $kind,
        ]);

        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $creator->id,
            'role' => GroupMember::COMMISSIONER,
        ]);

        // Never a hardcoded season: the calendar knows what year this is,
        // months before it is played.
        $group->contests()->create([
            'season_year' => $this->calendar->currentYear(),
            'mode' => $mode,
            'settings' => null,
        ]);

        // Both once-ever: founding your first group, and being in one at
        // all. Group number two pays nothing — the keys are the cap.
        $this->wallet->handle(
            $creator,
            GrantWalletEntry::FIRST_GROUP_CREATED_XP,
            0,
            GrantWalletEntry::REASON_FIRST_GROUP_CREATED,
            GrantWalletEntry::REASON_FIRST_GROUP_CREATED,
        );
        $this->wallet->handle(
            $creator,
            GrantWalletEntry::FIRST_GROUP_XP,
            0,
            GrantWalletEntry::REASON_FIRST_GROUP,
            GrantWalletEntry::REASON_FIRST_GROUP,
        );

        return $group;
    }

    /**
     * Uppercase alphanumeric, retried against the unique index. Eight
     * characters over a 36-glyph alphabet collide roughly never, but a
     * retry loop costs three lines and an unhandled QueryException costs a
     * user their group name.
     */
    private function uniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Group::where('code', $code)->exists());

        return $code;
    }
}
