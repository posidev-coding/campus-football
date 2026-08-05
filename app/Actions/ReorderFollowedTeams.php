<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Set the order of a user's followed teams.
 *
 * The order is the whole model — it drives the Home swipe order, the
 * scoreboard float order, and whose news leads — so this replaces what
 * `SetFavoriteTeam` used to do. Position 1 is the favorite.
 */
class ReorderFollowedTeams
{
    /**
     * @param  list<int>  $teamIds  every followed team, in the desired order
     */
    public function handle(User $user, array $teamIds): void
    {
        $current = $user->followedTeams()->pluck('teams.id')->all();

        /*
         * The submitted list must be EXACTLY what they follow — same members,
         * no repeats, nothing missing. This is reachable from a public
         * Livewire method, so the client can send anything: a team they do not
         * follow (which would silently attach it), a short list (which would
         * strand the rest at a stale position), or somebody else's team.
         * Rejecting outright beats partially applying a bad order.
         */
        $submitted = array_values(array_unique(array_map('intval', $teamIds)));

        if (count($submitted) !== count($current) || array_diff($submitted, $current) !== []) {
            return;
        }

        DB::transaction(function () use ($user, $submitted) {
            foreach ($submitted as $index => $teamId) {
                $user->followedTeams()->updateExistingPivot($teamId, ['position' => $index + 1]);
            }
        });
    }

    /**
     * Drop one team at an index — the shape `wire:sort` reports.
     *
     * Its handler is called with `($key, $position)`: the item that moved and
     * its new index, NOT the whole list. `$position` is Sortable's `newIndex`,
     * which is **0-based** — verified in
     * `vendor/livewire/livewire/dist/livewire.esm.js`, and the kind of thing
     * that silently produces an off-by-one rather than an error.
     *
     * Rebuilding the full order here and delegating means the drag path gets
     * the same membership validation as everything else.
     */
    public function place(User $user, int $teamId, int $position): void
    {
        $order = $user->followedTeams()->pluck('teams.id')->all();
        $from = array_search($teamId, $order, true);

        if ($from === false) {
            return;
        }

        array_splice($order, $from, 1);
        array_splice($order, max(0, min($position, count($order))), 0, [$teamId]);

        $this->handle($user, $order);
    }

    /**
     * Move one team up or down by a single place.
     *
     * The keyboard path to the same outcome as a drag — a drag handle is
     * unreachable without a pointer, and reordering is the only way to say
     * which team leads.
     */
    public function move(User $user, int $teamId, int $offset): void
    {
        $order = $user->followedTeams()->pluck('teams.id')->all();
        $from = array_search($teamId, $order, true);

        if ($from === false) {
            return;
        }

        $to = $from + $offset;

        if ($to < 0 || $to >= count($order)) {
            return;
        }

        [$order[$from], $order[$to]] = [$order[$to], $order[$from]];

        $this->handle($user, $order);
    }
}
