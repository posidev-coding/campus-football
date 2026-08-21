<?php

namespace App\Actions;

use App\Models\Game;
use App\Models\User;

/**
 * The Film Room: XP for actually reading a game, not just scanning a score.
 *
 * It pays for the SLOW surfaces — a preview before kickoff, a box score
 * after it — because those are the screens somebody opens to learn
 * something, and the reward is meant to find the reader who was going to
 * open them anyway rather than to invent a chore.
 *
 * Every guard is a key rather than a rule. The game id is the slot, so a box
 * score pays once ever no matter how many times it is opened, and the day is
 * in the key, so at most five DIFFERENT games pay per Eastern day. Nothing
 * throttles, nothing is scheduled, and a double-fired render inserts zero
 * rows — see {@see GrantWalletEntry::daily()}.
 *
 * Reading stays free: a guest or an unverified account earns nothing and is
 * shown nothing about it. This never gates the screen.
 */
class EnterFilmRoom
{
    /** The tabs that count as film — the rest of the game screen is a score. */
    public const TABS = ['preview', 'box'];

    public function __construct(private GrantWalletEntry $wallet) {}

    /**
     * @return bool whether this visit actually paid
     */
    public function handle(User $user, Game $game, string $tab): bool
    {
        if (! in_array($tab, self::TABS, true)) {
            return false;
        }

        return $this->wallet->daily(
            $user,
            GrantWalletEntry::FILM_ROOM_XP,
            0,
            GrantWalletEntry::REASON_FILM_ROOM,
            GrantWalletEntry::FILM_ROOM_DAILY_CAP,
            (string) $game->id,
        );
    }
}
