<?php

namespace App\Exceptions;

use Exception;

/**
 * The public room has no seat to give — every cap slot is taken, or its
 * week is already being played. Developer message only; the screen
 * answers with Voice's 'contest.room.full', and the lobby's inventory
 * should already be offering the next room.
 */
class ContestFull extends Exception
{
    public function __construct()
    {
        parent::__construct('This public contest has no open seats.');
    }
}
