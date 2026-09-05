<?php

namespace App\Support;

use App\Models\Group;
use App\Models\Slate;
use App\Models\TeamSeason;
use App\Models\User;
use App\Models\Week;
use App\Services\CfbCalendar;
use Illuminate\Support\Collection;

/**
 * The lobby's inventory READ — one home for "what is open right now", so
 * the browser and the dashboard's teaser can never disagree about it.
 *
 * The browser needs the whole graph (rooms, their slates, their seats);
 * the dashboard needs one NUMBER and must never pay for the graph to get
 * it. Both answers are built here from the same conditions, and
 * LobbyRoomsTest pins them equal — a count that drifts from the list is
 * a teaser that lies about the door it opens.
 *
 * SEAT-INCLUSIVE, deliberately: openRooms() returns rooms the viewer is
 * already sitting in, flagged rather than dropped. The lobby has to know
 * the difference between "you are in this one" and "this Saturday cannot
 * seat it", or a room you joined an hour ago renders as closed.
 */
class Lobby
{
    /**
     * Every open room this Saturday, INCLUDING the ones the viewer is
     * seated in — catalog order, with the viewer's own conference
     * fronting the conference family.
     *
     * @return Collection<int, Group>
     */
    public static function openRooms(?User $viewer): Collection
    {
        $weekId = app(CfbCalendar::class)->defaultWeekId(app(CfbCalendar::class)->currentYear());

        // The lobby sells ONE Saturday at a time — inside a split opening
        // week, 8/29's rooms and 9/5's must never share it.
        $week = $weekId === null ? null : Week::find($weekId);
        $target = $week === null ? null : Cadence::activeSaturday($week)?->toDateString();
        $conference = self::viewerConference($viewer);

        return Group::query()
            ->where('kind', Group::KIND_LOBBY)
            ->where(fn ($q) => $q
                ->whereNull('week_id')
                ->when($weekId !== null, fn ($qq) => $qq->orWhere(fn ($room) => $room
                    ->where('week_id', $weekId)
                    ->whereNull('filled_at'))))
            ->withCount('memberships')
            // The viewer's OWN seat, constrained to them: an empty
            // relation means "not seated", which is what seated() reads.
            ->with(['memberships' => fn ($q) => $q->where('user_id', $viewer?->id ?? 0)])
            ->with(['contests.slates' => fn ($q) => $q
                ->select('id', 'contest_id', 'week_id', 'status', 'saturday')
                ->withCount('games')
                // The kickoffs too, because "open" now means the card has not
                // started — see the filter below. Bounded by the catalog, so
                // this is a handful of rooms, not a table scan.
                ->with('games.game:id,kickoff_at,status,completed')])
            ->get()
            ->filter(function (Group $group) use ($target) {
                if (! $group->isRoom()) {
                    return true;
                }

                if ($group->member_cap !== null && $group->memberships_count >= $group->member_cap) {
                    return false;
                }

                // Open means PICKABLE: this Saturday's slate is out, has not
                // started, and is not yet settled away. Underway is part of
                // the READ and not just of JoinGroup's guard, or the lobby
                // offers a seat the action is bound to refuse.
                return $group->contests->first()
                    ?->slates->contains(fn ($slate) => $slate->week_id === $group->week_id
                        && $slate->status === Slate::PUBLISHED
                        && ($target === null || $slate->saturday?->toDateString() === $target)
                        && ! $slate->isUnderway()) ?? false;
            })
            // Catalog order, not alphabetical — the standard rooms lead,
            // the specialty shelf follows, and the viewer's own conference
            // fronts the conference family. The conference is resolved ONCE
            // for the sort, not once per room.
            ->sortBy(fn (Group $group) => LobbyCatalog::sortKey($group, $conference))
            ->values();
    }

    /**
     * The rooms actually for SALE: openRooms() minus the seats the viewer
     * already holds. What the Join list is built from.
     *
     * @return Collection<int, Group>
     */
    public static function joinable(?User $viewer): Collection
    {
        return self::openRooms($viewer)
            ->reject(fn (Group $group) => self::seated($group))
            ->values();
    }

    /** Whether the viewer already holds a seat in this room. */
    public static function seated(Group $room): bool
    {
        return $room->relationLoaded('memberships') && $room->memberships->isNotEmpty();
    }

    /**
     * How many rooms are open to this viewer THIS SATURDAY — the teaser's
     * number, and nothing more than a number.
     *
     * The dashboard reads this instead of joinable() because the teaser
     * needs an integer, not the inventory graph. Evergreen house lobbies
     * are excluded on purpose: the teaser sells the Saturday, and an
     * always-open table is not a Saturday product.
     */
    public static function openRoomCount(?User $viewer): int
    {
        $weekId = app(CfbCalendar::class)->defaultWeekId(app(CfbCalendar::class)->currentYear());

        if ($weekId === null) {
            return 0;
        }

        $week = Week::find($weekId);
        $target = $week === null ? null : Cadence::activeSaturday($week)?->toDateString();

        if ($target === null) {
            return 0;
        }

        return Group::query()
            ->where('kind', Group::KIND_LOBBY)
            ->where('week_id', $weekId)
            ->whereNull('filled_at')
            ->whereDoesntHave('memberships', fn ($q) => $q->where('user_id', $viewer?->id ?? 0))
            ->where(fn ($q) => $q
                ->whereNull('member_cap')
                ->orWhereRaw('(select count(*) from group_members where group_members.group_id = groups.id) < groups.member_cap'))
            ->whereHas('contests.slates', fn ($q) => $q
                ->where('slates.week_id', $weekId)
                ->where('slates.status', Slate::PUBLISHED)
                ->where('slates.saturday', $target)
                // The same "not started" condition openRooms() applies in PHP,
                // as SQL — this method exists to be the LIST's count without
                // paying for the graph, so a condition on one and not the
                // other is a teaser that lies about the door it opens.
                ->whereDoesntHave('games.game', fn ($game) => $game->kickedOff()))
            ->count();
    }

    /**
     * The conference of the viewer's FIRST followed team this season —
     * the room the conference family leads with, for them. Null for
     * guests and the unaffiliated: catalog order.
     */
    public static function viewerConference(?User $viewer): ?string
    {
        $teamId = $viewer?->followedTeams()
            ->orderBy('team_follows.position')
            ->value('teams.id');

        if ($teamId === null) {
            return null;
        }

        return TeamSeason::query()
            ->where('team_id', $teamId)
            ->where('season_year', app(CfbCalendar::class)->currentYear())
            ->join('conferences', 'conferences.id', '=', 'team_seasons.conference_id')
            ->value('conferences.abbreviation');
    }
}
