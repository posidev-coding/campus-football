<?php

namespace App\Support;

use App\Models\Group;
use App\Models\Slate;
use App\Models\User;
use App\Models\Week;
use App\Services\CfbCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * EVERY SEAT THE VIEWER HOLDS — one read, and the one partition of it.
 *
 * My Picks' cards() and the group switcher on both pick'em screens read
 * THIS, so the switcher's menu and the overview's sections can never
 * list a different set of groups, and neither can file a room under the
 * wrong Saturday. Before this class the read lived inline in cards(),
 * which the clubhouse could not reach without a second copy of it.
 *
 * Built once per screen and held in a `#[Computed]` — Livewire memoizes
 * it per request — then handed to the switcher as a prop. Nothing here
 * is static, so there is nothing to flush between tests and nothing to
 * go stale after a JoinGroup inside the same process.
 *
 * LAZY past the groups themselves. A zero-seat reader pays one query and
 * nothing else; the clubhouse pays the week only when it prints the
 * contests heading; the room Saturdays are read only when a room is
 * actually held. `Cadence::activeSaturday` is unmemoized, so it is asked
 * at most ONCE here and cards() no longer asks it at all.
 *
 * The partition is the product's own: a private GROUP runs all season; a
 * public ROOM plays one Saturday and is past once that Saturday is
 * behind the one being sold; an always-open TABLE is a lobby with no
 * week. Nothing is deleted when a room goes past — its membership, its
 * URL and its leaderboard outlive it (components-support.md).
 */
final class Seats
{
    private ?Week $week = null;

    private bool $weekResolved = false;

    private ?CarbonImmutable $saturday = null;

    private bool $saturdayResolved = false;

    /** @var Collection<int, string|null>|null group id => 'Y-m-d' */
    private ?Collection $roomSaturdays = null;

    private ?int $openCount = null;

    /**
     * @param  Collection<int, Group>  $groups  every seat, with `pivot->role` and `memberships_count`, by name
     */
    private function __construct(
        private readonly ?User $user,
        public readonly Collection $groups,
    ) {}

    /** A guest holds nothing and costs nothing. */
    public static function for(?User $user): self
    {
        $groups = $user === null
            ? collect()
            : $user->groups()->withCount('memberships')->orderBy('name')->get();

        return new self($user, $groups);
    }

    public function hasSeats(): bool
    {
        return $this->groups->isNotEmpty();
    }

    /**
     * The week whose card is being sold, or null when the calendar has
     * none — never a substituted week.
     */
    public function week(): ?Week
    {
        if (! $this->weekResolved) {
            $calendar = app(CfbCalendar::class);
            $weekId = $calendar->defaultWeekId($calendar->currentYear());

            $this->week = $weekId === null ? null : Week::find($weekId);
            $this->weekResolved = true;
        }

        return $this->week;
    }

    public function weekId(): ?int
    {
        return $this->week()?->id;
    }

    /** The Saturday being sold — the one question the whole pick'em week hangs on. */
    public function saturday(): ?CarbonImmutable
    {
        if (! $this->saturdayResolved) {
            $week = $this->week();

            $this->saturday = $week === null ? null : Cadence::activeSaturday($week);
            $this->saturdayResolved = true;
        }

        return $this->saturday;
    }

    /**
     * THE SATURDAY EACH ROOM ACTUALLY PLAYS, keyed by group — the one
     * read the card query cannot answer, because that one is filtered to
     * the card being sold and a played room is by definition off it.
     * This is what tells "the Saturday you played" apart from "your slate
     * was taken away", which are the same absence on the card and two
     * different sentences on the screen.
     *
     * DATE STRINGS, and every comparison downstream is a string one.
     * `slates.saturday` is a date column cast at the app's timezone while
     * the card being sold is an ET midnight — comparing the two as
     * instants makes the SAME Saturday four hours "earlier" and files a
     * live room under played. Every other Saturday comparison in this
     * codebase is toDateString(); this one is too.
     *
     * @return Collection<int, string|null>
     */
    public function roomSaturdays(): Collection
    {
        if ($this->roomSaturdays === null) {
            $roomIds = $this->groups->filter(fn (Group $group) => $group->isRoom())->pluck('id');

            $this->roomSaturdays = $roomIds->isEmpty() ? collect() : Slate::query()
                ->join('contests', 'contests.id', '=', 'slates.contest_id')
                ->whereIn('contests.group_id', $roomIds)
                ->where('contests.season_year', app(CfbCalendar::class)->currentYear())
                ->orderBy('slates.saturday')
                ->pluck('slates.saturday', 'contests.group_id')
                ->map(fn ($day) => match (true) {
                    $day === null => null,
                    $day instanceof CarbonInterface => $day->toDateString(),
                    default => substr((string) $day, 0, 10),
                });
        }

        return $this->roomSaturdays;
    }

    /**
     * A ROOM WHOSE SATURDAY HAS BEEN PLAYED — a week behind us, OR a
     * Saturday behind the one being sold.
     *
     * The second half is the whole rule: an ESPN week holds two
     * Saturdays (2026 Week 1 = 8/29 and 9/5), so a room that played 8/29
     * still satisfies `week_id === weekId` on the Tuesday after, and
     * would carry into the fresh week as "this Saturday" — dead seats
     * stacked over the reader's own groups. Read the room's OWN Saturday
     * and compare it to the card being sold.
     *
     * A room with no slate at all is NOT past: its card was never
     * published or was taken away, which is a different sentence and
     * must not be inferred from a missing row.
     */
    public function isPast(Group $group): bool
    {
        if (! $group->isRoom()) {
            return false;
        }

        if ($group->week_id !== $this->weekId()) {
            return true;
        }

        $own = $this->roomSaturdays()->get($group->id);
        $selling = $this->saturday();

        return $own !== null && $selling !== null && $own < $selling->toDateString();
    }

    /** @return Collection<int, Group> season-long private groups, by name */
    public function privateGroups(): Collection
    {
        return $this->groups->reject(fn (Group $group) => $group->isLobby())->values();
    }

    /** @return Collection<int, Group> public rooms still on the Saturday being sold */
    public function rooms(): Collection
    {
        return $this->groups
            ->filter(fn (Group $group) => $group->isRoom() && ! $this->isPast($group))
            ->values();
    }

    /** @return Collection<int, Group> public rooms whose Saturday has been played */
    public function pastRooms(): Collection
    {
        return $this->groups
            ->filter(fn (Group $group) => $group->isRoom() && $this->isPast($group))
            ->values();
    }

    /** @return Collection<int, Group> the always-open house tables: a lobby with no week */
    public function tables(): Collection
    {
        return $this->groups
            ->filter(fn (Group $group) => $group->isLobby() && ! $group->isRoom())
            ->values();
    }

    /**
     * The week a FAN would name — "Week 0" is a real answer inside a
     * split opening week, and null means the calendar has no week, which
     * skips a heading rather than inventing one.
     */
    public function weekLabel(): ?string
    {
        $week = $this->week();

        return $week === null ? null : Cadence::displayWeekLabel($week, $this->saturday());
    }

    /**
     * How many rooms are open to this viewer this Saturday — the lobby
     * door's number, and only a number. LobbyRoomsTest pins it equal to
     * what the Lobby lists.
     */
    public function openCount(): int
    {
        return $this->openCount ??= Lobby::openRoomCount($this->user);
    }
}
