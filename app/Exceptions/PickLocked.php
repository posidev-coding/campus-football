<?php

namespace App\Exceptions;

use Exception;

/**
 * A pick was attempted on a game that has kicked off.
 *
 * The lock is temporal — Game::hasKickedOff(), by clock or by feed — and
 * never a stored flag. Developer message only; the sheet's lock state is
 * what the user actually sees, rendered before this could ever throw.
 */
class PickLocked extends Exception
{
    public function __construct()
    {
        parent::__construct('This game has kicked off; picks are locked.');
    }
}
