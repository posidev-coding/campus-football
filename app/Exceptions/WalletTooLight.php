<?php

namespace App\Exceptions;

use Exception;

/**
 * The seat costs a Tallboy and the wallet cannot cover it.
 *
 * The third refusal a public room can give, beside ContestFull and
 * PickemParticipationGated. Developer message only: what the reader sees
 * comes from Voice ('contest.room.too_light'), because a string baked into
 * an exception can only ever speak in one register.
 *
 * A refusal, never an overdraft — no spend may take a wallet negative, and
 * the ledger has no balance column to correct afterwards.
 */
class WalletTooLight extends Exception
{
    public function __construct()
    {
        parent::__construct('This room costs a Tallboy and the wallet is short.');
    }
}
