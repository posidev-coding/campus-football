<?php

namespace App\Enums;

use App\Services\Contests\ClassicMode;
use App\Services\Contests\ModeEngine;
use App\Services\Contests\TieredMode;
use App\Services\Contests\WoodshedMode;

/**
 * The three ways a contest can be played. Every mode picks against the
 * spread — that is the product's hard rule, not a mode's choice.
 *
 * The backing values are deliberately neutral where the product name is
 * marketing: `classic` wears "Shotgun" on its screens and `tiered` wears
 * "Triple Option", so a rename never touches data. Labels here are product
 * vocabulary — constant across ContentRating registers, like the rank
 * ladder's names; the copy AROUND them is what Voice varies.
 *
 * Every mode's perfect week lands at ~100 by design: Shotgun 10×10,
 * Triple Option 45+35+20, and the Woodshed 90 + 6 (Lock) + 5 (Bear) = 101
 * — the founders' game keeps a one-point premium.
 */
enum ContestMode: string
{
    /** Ten games by default, every one worth the same. The casual door. */
    case Classic = 'classic';

    /** 15 games in 3 tiers of progressive quality. The main event. */
    case Tiered = 'tiered';

    /** The founders' game, recovered and live: the Lock and the Bear. */
    case Woodshed = 'woodshed';

    public function label(): string
    {
        return match ($this) {
            self::Classic => 'Shotgun',
            self::Tiered => 'Triple Option',
            self::Woodshed => 'The Woodshed',
        };
    }

    /**
     * The one-line rules pitch — product vocabulary like label(), constant
     * across registers, shared by the mode cards and the lobby's room
     * cards so the mode is never described two ways.
     *
     * `$games` is the CONTEST's own slate size, passed by every caller
     * that holds one. Shotgun flexes — Week 0 seats eight games, not ten
     * — and a pitch that says "10 games" over an eight-game card is the
     * room lying about the game it is selling. Null means "the mode's own
     * shape", which is the honest answer for the doors and the lobby
     * explainer, where there is no contest to describe.
     */
    public function blurb(?int $games = null): string
    {
        return match ($this) {
            self::Classic => $this->cardSize($games).' games, '.ClassicMode::GAME_POINTS.' points each. Every call counts the same.',
            self::Tiered => '15 games in three tiers — 9 points up top, then 7, then 4.',
            self::Woodshed => 'The founders\' game: 15 games at 8, 6 and 4. Lock one call, beat the Bear.',
        };
    }

    /**
     * How many games a description is about: the caller's frozen size, or
     * this mode's own default. The default is the ENGINE's, never a
     * literal in the copy — one number, in one place, so a rebalance
     * cannot leave the sentence behind.
     *
     * Only Shotgun's copy varies with it: the tiered modes are fifteen or
     * nothing
     * (their tier specs cannot scale, and their engines ignore the knob),
     * so their copy states the count outright.
     */
    private function cardSize(?int $games): int
    {
        return $games ?? $this->engine()->slateSize();
    }

    /**
     * Modes a group can actually field today: all three, since the
     * Woodshed's rules landed. A future mode gates here until it is real.
     */
    public function available(): bool
    {
        return true;
    }

    /** The mode's mark — a Flux icon view name, passed as a child. */
    public function icon(): string
    {
        return match ($this) {
            self::Classic => 'bullseye',
            self::Tiered => 'diagram-3',
            self::Woodshed => 'hammer',
        };
    }

    /**
     * The mode's colors as FULL static Tailwind class strings — written
     * out so the build's content scan can see every one (an interpolated
     * class fails silently as a design bug). Shotgun wears cyan, Triple
     * Option violet; the Woodshed is dark in BOTH themes, red-accented —
     * the founders' game keeps its own weather. None of these borrow the
     * state colors (live red-on-light, prelim amber, final green) or the
     * app's action blue.
     *
     * @return array{chip: string, icon: string, tile: string, body: string}
     */
    public function palette(): array
    {
        return match ($this) {
            self::Classic => [
                'chip' => 'bg-cyan-100 text-cyan-800 dark:bg-cyan-950 dark:text-cyan-300',
                'icon' => 'text-cyan-600 dark:text-cyan-400',
                'tile' => 'border-cyan-200 bg-cyan-50/50 dark:border-cyan-900 dark:bg-cyan-950/30',
                'body' => 'text-zinc-500 dark:text-zinc-400',
            ],
            self::Tiered => [
                'chip' => 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
                'icon' => 'text-violet-600 dark:text-violet-400',
                'tile' => 'border-violet-200 bg-violet-50/50 dark:border-violet-900 dark:bg-violet-950/30',
                'body' => 'text-zinc-500 dark:text-zinc-400',
            ],
            self::Woodshed => [
                'chip' => 'bg-zinc-900 text-red-300 ring-1 ring-red-900/50 dark:bg-black dark:text-red-400 dark:ring-red-950',
                'icon' => 'text-red-500',
                'tile' => 'border-red-900/40 bg-zinc-900 text-zinc-100 dark:border-red-950 dark:bg-black',
                'body' => 'text-zinc-400',
            ],
        };
    }

    /**
     * The mode's rules as plain instructional lines — the ONE source the
     * lobby's explainer, the mode doors, the join landing and the docs all
     * read, so the game is never described two ways. Product facts,
     * constant across registers; Voice speaks AROUND them, never in them.
     *
     * Sized like blurb(): pass the contest's slate size wherever there is
     * a contest, and a downsized Shotgun card states its own count and
     * its own perfect week rather than the mode's ten and hundred.
     *
     * @return list<string>
     */
    public function ruleLines(?int $games = null): array
    {
        $count = $this->cardSize($games);

        return match ($this) {
            self::Classic => [
                $count.' games against the spread, every one worth '.ClassicMode::GAME_POINTS.' points.',
                'A perfect week is '.($count * ClassicMode::GAME_POINTS).'.',
            ],
            self::Tiered => [
                '15 games in three tiers of five, by game quality.',
                'Tier 1 pays 9, tier 2 pays 7, tier 3 pays 4.',
                'A perfect week is 100.',
            ],
            self::Woodshed => [
                '15 games in three tiers of five: 8, 6 and 4 points.',
                'The Lock: stake the featured game for +6 right, −4 wrong. Optional, one a week.',
                'The Bear picks every game on a weekly theme. Beat his total outright for 5 bonus points.',
                'A perfect week is 101 — the founders\' premium.',
            ],
        };
    }

    /**
     * The rules of the game, as an object. `$settings` is the contest's
     * knob column — null means the mode's own defaults.
     */
    public function engine(?array $settings = null): ModeEngine
    {
        return match ($this) {
            self::Classic => new ClassicMode($settings),
            self::Tiered => new TieredMode($settings),
            self::Woodshed => new WoodshedMode($settings),
        };
    }
}
