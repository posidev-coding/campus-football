<?php

namespace App\Support;

use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Models\Contest;
use App\Models\Week;
use App\Services\Contests\SuggestSlate;
use Carbon\CarbonInterface;

/**
 * The public floor's inventory list: which rooms the stocking sweep keeps
 * open, in the order the floor presents them — and whether a given room
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
     * The floor, in stocking-and-display order: the three standard rooms
     * (the preflight's red line), then the specialty shelf.
     *
     * @return list<array{mode: ContestMode, flavor: ?LobbyFlavor}>
     */
    public static function entries(): array
    {
        return [
            ['mode' => ContestMode::Classic, 'flavor' => null],
            ['mode' => ContestMode::Tiered, 'flavor' => null],
            ['mode' => ContestMode::Woodshed, 'flavor' => null],
            // The specialty shelf opens with the catalog flip — flavors
            // spawn through the same door either way, so enabling one is
            // one line here.
        ];
    }

    /**
     * What a room of this shape carries on this Saturday — or null when
     * the Saturday cannot support it, which means NO ROOM: a thin board
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
