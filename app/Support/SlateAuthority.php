<?php

namespace App\Support;

use App\Exceptions\NotGroupCommissioner;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use InvalidArgumentException;

/**
 * The three guards every slate mutation runs, in one place so five Actions
 * cannot drift on what "allowed" means. All of them THROW — these actions
 * are reachable from public Livewire methods, and a guard that returns
 * false politely is a gate somebody will forget to check.
 */
class SlateAuthority
{
    /** @throws NotGroupCommissioner */
    public static function commissioner(User $actor, Slate $slate): void
    {
        $slate->loadMissing('contest');

        $runsIt = GroupMember::query()
            ->where('group_id', $slate->contest->group_id)
            ->where('user_id', $actor->id)
            ->where('role', GroupMember::COMMISSIONER)
            ->exists();

        if (! $runsIt) {
            throw new NotGroupCommissioner;
        }
    }

    /** A published board is frozen history; only drafts are editable. */
    public static function draft(Slate $slate): void
    {
        if ($slate->status !== Slate::DRAFT) {
            throw new InvalidArgumentException("Slate {$slate->id} is not a draft.");
        }
    }

    /** The slate game must actually belong to the slate being edited. */
    public static function onSlate(Slate $slate, SlateGame $slateGame): void
    {
        if ($slateGame->slate_id !== $slate->id) {
            throw new InvalidArgumentException("Slate game {$slateGame->id} is not on slate {$slate->id}.");
        }
    }
}
