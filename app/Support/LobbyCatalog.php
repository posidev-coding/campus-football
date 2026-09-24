<?php

namespace App\Support;

use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Enums\LobbyShelf;
use App\Models\Contest;
use App\Models\Group;
use App\Models\Slate;
use App\Models\Week;
use App\Services\Contests\SuggestSlate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The lobby's inventory list: which rooms the stocking sweep keeps
 * open, in the order the lobby presents them — and whether a given room
 * is even possible on a given Saturday.
 *
 * Feasibility is the load-bearing half. The season's opening Saturday
 * holds eight usable games: the fifteen-game modes cannot publish at all,
 * a standard Shotgun card has to downsize, and a themed room may not find
 * its minimum. Every rule about that lives HERE, so the spawner stays a
 * provisioner and the preflight can exempt an impossible shelf instead of
 * failing it.
 */
class LobbyCatalog
{
    /** The smallest card a dynamic-size room may sell. */
    private const MIN_DYNAMIC = 8;

    /** The smallest downsized standard Shotgun card — Week 0's reality. */
    private const MIN_FLEX = 5;

    /**
     * How many Saturdays back a specialty flavor's take-up is read. Three
     * is long enough that one quiet weekend does not close a shelf, and
     * short enough that a flavor nobody has touched in a month stops being
     * stocked, swept and reminded about every hour.
     */
    public const DEMAND_SATURDAYS = 3;

    /**
     * Unclaimed flavors stocked anyway each Saturday, so a shelf closed for
     * lack of take-up can earn its way back. Two of ten puts every flavor
     * back on the shelf about every five weeks.
     */
    public const ROTATION = 2;

    /**
     * The inventory, in stocking-and-display order: the three standard rooms
     * (the preflight's red line), then the specialty shelf.
     *
     * @return list<array{mode: ContestMode, flavor: ?LobbyFlavor}>
     */
    public static function entries(): array
    {
        $entries = [
            ['mode' => ContestMode::Classic, 'flavor' => null],
            ['mode' => ContestMode::Tiered, 'flavor' => null],
            ['mode' => ContestMode::Woodshed, 'flavor' => null],
        ];

        // The specialty shelf, in LobbyFlavor case order — feasibility
        // trims it per Saturday (no ranked poll, no night games, a thin
        // conference: no room), so listing a flavor here is an offer, not
        // a promise.
        foreach (LobbyFlavor::cases() as $flavor) {
            $entries[] = ['mode' => $flavor->mode(), 'flavor' => $flavor];
        }

        return $entries;
    }

    /**
     * What THIS Saturday's shelf offers: the three standard rooms always,
     * and each specialty flavor only when the take-up says so (CFB-100).
     *
     * Two Saturdays of production stocked twelve and thirteen house rooms,
     * and every specialty room seated nobody while the one private group
     * seated fifteen. A room the sweep keeps open costs a slate suggestion,
     * an hourly restock check and a reminder cadence, whoever takes it. So a
     * flavor is stocked when:
     *
     *   - one of its rooms seated anybody in the last DEMAND_SATURDAYS, or
     *   - it had NO room in that window at all: no history is not a zero,
     *     and treating it as one would empty the shelf on the first quiet
     *     week with nothing ever able to bring it back, or
     *   - it is this Saturday's turn in the ROTATION of the rest.
     *
     * The standard three are unconditional. PickemPreflight treats them as
     * the red line, and a lobby with no Classic room is not a lobby.
     *
     * This is an OFFER, like entries(): feasibility still trims it through
     * resolve() at spawn. One query, and never from a per-row render.
     *
     * @return list<array{mode: ContestMode, flavor: ?LobbyFlavor}>
     */
    public static function shelf(CarbonInterface $saturday): array
    {
        $offered = self::offeredFlavors($saturday);

        return array_values(array_filter(
            self::entries(),
            fn (array $entry): bool => $entry['flavor'] === null || in_array($entry['flavor'], $offered, true),
        ));
    }

    /**
     * The specialty flavors this Saturday offers, in case order.
     *
     * @return list<LobbyFlavor>
     */
    public static function offeredFlavors(CarbonInterface $saturday): array
    {
        $takeUp = self::takeUp($saturday);

        $claimed = [];
        $unclaimed = [];

        foreach (LobbyFlavor::cases() as $flavor) {
            // Absent from the map means no room in the window: stock it.
            if (($takeUp[$flavor->value] ?? null) === null || $takeUp[$flavor->value] > 0) {
                $claimed[] = $flavor;
            } else {
                $unclaimed[] = $flavor;
            }
        }

        return array_values(array_filter(
            LobbyFlavor::cases(),
            fn (LobbyFlavor $flavor): bool => in_array($flavor, $claimed, true)
                || in_array($flavor, self::rotation($unclaimed, $saturday), true),
        ));
    }

    /**
     * Members seated per flavor across its rooms in the DEMAND_SATURDAYS
     * before this one. A flavor with no room in the window is ABSENT, not
     * zero, and only absence means "unknown".
     *
     * Read by the Saturday the room's card was on, never by week: an ESPN
     * week can hold two Saturdays (.ai/rules/views-livewire-support.md).
     *
     * @return array<string, int> flavor value => members seated
     */
    private static function takeUp(CarbonInterface $saturday): array
    {
        // The Saturday's own date, immutable: a mutable instance would move
        // under the first subDays() and take the upper bound with it.
        $day = CarbonImmutable::parse($saturday->toDateString());
        $from = $day->subDays(7 * self::DEMAND_SATURDAYS)->toDateString();
        $before = $day->toDateString();

        return Group::query()
            ->where('kind', Group::KIND_LOBBY)
            ->whereNotNull('flavor')
            ->whereHas('contests.slates', fn ($slates) => $slates
                ->where('status', '!=', Slate::DRAFT)
                ->where('saturday', '>=', $from)
                ->where('saturday', '<', $before))
            ->withCount('memberships')
            ->get(['id', 'flavor'])
            ->groupBy('flavor')
            ->map(fn (Collection $rooms): int => (int) $rooms->sum('memberships_count'))
            ->all();
    }

    /**
     * This Saturday's turn among the unclaimed flavors: ROTATION of them,
     * chosen by the Saturday itself, so every hourly sweep of one Saturday
     * stocks the same two and the next Saturday moves on.
     *
     * @param  list<LobbyFlavor>  $unclaimed
     * @return list<LobbyFlavor>
     */
    private static function rotation(array $unclaimed, CarbonInterface $saturday): array
    {
        $count = count($unclaimed);

        if ($count <= self::ROTATION) {
            return $unclaimed;
        }

        $weekNumber = intdiv(CarbonImmutable::parse($saturday->toDateString(), 'UTC')->getTimestamp(), 7 * 86_400);
        $start = ($weekNumber * self::ROTATION) % $count;

        return array_map(fn (int $i): LobbyFlavor => $unclaimed[($start + $i) % $count], range(0, self::ROTATION - 1));
    }

    /**
     * The lobby's display order: standard rooms first in mode order, then
     * the specialty shelf in flavor-case order — with the viewer's own
     * conference leading the conference family, which is the whole reason
     * the family exists — and evergreen lobbies last. Name breaks ties.
     *
     * @return array{0: int, 1: float, 2: string}
     */
    public static function sortKey(Group $room, ?string $viewerConference = null): array
    {
        if (! $room->isRoom()) {
            return [2, 0.0, (string) $room->name];
        }

        $flavor = $room->flavorEnum();

        if ($flavor === null) {
            $index = array_search($room->contests->first()?->mode, ContestMode::cases(), true);

            return [0, (float) ($index === false ? 99 : $index), (string) $room->name];
        }

        $index = (float) array_search($flavor, LobbyFlavor::cases(), true);

        if ($viewerConference !== null && $flavor->conference() === $viewerConference) {
            // Half a step in front of the conference block: first of the
            // family, never ahead of the shelf before it.
            $index = array_search(LobbyFlavor::SecShowdown, LobbyFlavor::cases(), true) - 0.5;
        }

        return [1, $index, (string) $room->name];
    }

    /**
     * The lobby, shelved: the open rooms grouped into the four blocks the
     * browser sells them under, plus the catalog entries that could NOT
     * be stocked this Saturday as dashed "closed" rows.
     *
     * ZERO new queries. Everything here is a projection of the collection
     * Lobby::openRooms() already read — game counts come off the
     * eager-loaded published slate, seats off the loaded count. Never
     * call resolve()/viableCount() from a render: feasibility is a sweep
     * question, and asking it per request is a slate suggestion per row.
     *
     * The closed rows are an INFERENCE, and only a safe one when the
     * sweep has demonstrably run: with no open room at all we cannot tell
     * "the Saturday can't seat it" from "nothing has been stocked yet",
     * so an empty lobby shows no closed rows either. A room the viewer is
     * SEATED in counts as stocked — the shape exists, they are in it — so
     * it never renders as closed behind their back.
     *
     * A flavor this Saturday's shelf does not OFFER is never dashed. The
     * closed rows say "not enough games", and a flavor left off for lack of
     * take-up is not that. So the caller passes {@see shelf()}, read once per
     * render. The full catalog is the default for a caller with no Saturday.
     *
     * @param  Collection<int, Group>  $rooms  seat-inclusive, in sortKey order
     * @param  list<array{mode: ContestMode, flavor: ?LobbyFlavor}>|null  $offered  this Saturday's shelf
     * @return list<array{shelf: LobbyShelf, rooms: list<array{room: Group, mode: ContestMode, gameCount: ?int, seats: int, seated: bool}>, closed: list<array{mode: ContestMode, flavor: ?LobbyFlavor, label: string}>}>
     */
    public static function shelves(Collection $rooms, ?array $offered = null): array
    {
        $transient = $rooms->filter(fn (Group $room) => $room->isRoom());

        $stocked = [];
        $byShelf = [];

        foreach ($transient as $room) {
            $mode = $room->contests->first()?->mode;

            if ($mode === null) {
                continue;
            }

            $flavor = $room->flavorEnum();
            $shelf = $flavor?->shelf() ?? LobbyShelf::House;
            $stocked[self::shapeKey($mode, $flavor)] = true;

            $byShelf[$shelf->value][] = [
                'room' => $room,
                'mode' => $mode,
                'gameCount' => $room->contests->first()?->slates
                    ->first(fn ($slate) => $slate->week_id === $room->week_id && $slate->status === Slate::PUBLISHED)
                    ?->games_count,
                'seats' => (int) $room->memberships_count,
                'seated' => Lobby::seated($room),
                'locked' => Lobby::started($room),
            ];
        }

        // No open room means no evidence either way — say nothing rather
        // than dash out the whole catalog.
        $closedByShelf = [];

        if ($stocked !== []) {
            foreach ($offered ?? self::entries() as $entry) {
                if (isset($stocked[self::shapeKey($entry['mode'], $entry['flavor'])])) {
                    continue;
                }

                $shelf = $entry['flavor']?->shelf() ?? LobbyShelf::House;

                $closedByShelf[$shelf->value][] = [
                    'mode' => $entry['mode'],
                    'flavor' => $entry['flavor'],
                    'label' => $entry['flavor']?->label() ?? $entry['mode']->label(),
                ];
            }
        }

        $shelves = [];

        // Case order IS display order.
        foreach (LobbyShelf::cases() as $shelf) {
            $open = $byShelf[$shelf->value] ?? [];
            $closed = $closedByShelf[$shelf->value] ?? [];

            if ($open === [] && $closed === []) {
                continue;
            }

            $shelves[] = ['shelf' => $shelf, 'rooms' => $open, 'closed' => $closed];
        }

        return $shelves;
    }

    /** One (mode, flavor) shape, as an array key. */
    private static function shapeKey(ContestMode $mode, ?LobbyFlavor $flavor): string
    {
        return $mode->value.'|'.($flavor?->value ?? '');
    }

    /**
     * What a room of this shape carries on this Saturday — or null when
     * the Saturday cannot support it, which means NO ROOM: a thin slate
     * that lies about its flavor is worse than an empty shelf.
     *
     * The returned settings are frozen onto the room's contest at spawn.
     * Dynamic-size flavors get the Saturday's whole admitted count;
     * standard Shotgun downsizes to the card that exists (never below
     * MIN_FLEX); the tiered modes are fifteen or nothing — their tier
     * specs cannot scale.
     *
     * @return array{settings: ?array}|null
     */
    public static function resolve(ContestMode $mode, ?LobbyFlavor $flavor, Week $week, CarbonInterface $saturday): ?array
    {
        $base = $flavor?->settings();

        // An unsaved probe: viableCount() only reads mode + settings, and
        // sharing SuggestSlate's exact pipeline is what makes a frozen
        // size publishable by construction.
        $viable = app(SuggestSlate::class)->viableCount(
            (new Contest)->forceFill(['mode' => $mode, 'settings' => $base]),
            $week,
            $saturday,
        );

        $engineDefault = $mode->engine()->slateSize();

        if ($mode !== ContestMode::Classic) {
            return $viable >= $engineDefault ? ['settings' => $base] : null;
        }

        if ($flavor?->dynamicSize() === true) {
            return $viable >= self::MIN_DYNAMIC
                ? ['settings' => [...($base ?? []), 'slate_size' => $viable]]
                : null;
        }

        $fixed = $base['slate_size'] ?? null;

        if ($fixed !== null) {
            return $viable >= $fixed ? ['settings' => $base] : null;
        }

        if ($viable >= $engineDefault) {
            return ['settings' => $base];
        }

        return $viable >= self::MIN_FLEX
            ? ['settings' => [...($base ?? []), 'slate_size' => $viable]]
            : null;
    }
}
