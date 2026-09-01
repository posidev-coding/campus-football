<?php

namespace App\Actions;

use App\Exceptions\NotGroupCommissioner;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The clubhouse icon — a commissioner's one piece of group identity.
 *
 * The seat is the whole gate, and it is enough on its own: a public room is
 * house-run with no commissioner seat at all, so a lobby can never reach the
 * write without a second branch saying so.
 *
 * The old file is deleted AFTER the new path is committed, never before.
 * Reversed, a failed upload leaves the group with no icon and no way back to
 * the one it had — and a group icon is the thing every member recognizes the
 * clubhouse by.
 */
class SetGroupIcon
{
    /**
     * @throws NotGroupCommissioner
     */
    public function handle(User $actor, Group $group, UploadedFile $file): Group
    {
        $this->assertRunsIt($actor, $group);

        $previous = $group->icon;
        $path = $file->store('group-icons', config('cfb.upload_disk'));

        $group->forceFill(['icon' => $path])->save();

        $this->forget($previous, $path);

        return $group;
    }

    /**
     * Back to initials. Null means "no icon" everywhere it is read, so
     * clearing is the same write the group shipped with.
     *
     * @throws NotGroupCommissioner
     */
    public function clear(User $actor, Group $group): Group
    {
        $this->assertRunsIt($actor, $group);

        $previous = $group->icon;

        $group->forceFill(['icon' => null])->save();

        $this->forget($previous, null);

        return $group;
    }

    /**
     * @throws NotGroupCommissioner
     */
    private function assertRunsIt(User $actor, Group $group): void
    {
        $runsIt = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $actor->id)
            ->where('role', GroupMember::COMMISSIONER)
            ->exists();

        if (! $runsIt) {
            throw new NotGroupCommissioner;
        }
    }

    private function forget(?string $previous, ?string $current): void
    {
        if (filled($previous) && $previous !== $current) {
            Storage::disk(config('cfb.upload_disk'))->delete($previous);
        }
    }
}
