<?php

namespace App\Exceptions;

use Exception;

/**
 * A pick'em participation action was attempted by an unverified account.
 *
 * Verification gates PARTICIPATION — creating and joining groups, making
 * picks, posting — never reading. Developer message only: what the user
 * reads comes from Voice ('groups.verify_first' and friends), because a
 * string baked into an exception can only speak one register.
 */
class PickemParticipationGated extends Exception
{
    public function __construct()
    {
        parent::__construct('Pick\'em participation requires a verified email.');
    }
}
