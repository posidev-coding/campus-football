<?php

namespace App\Exceptions;

use Exception;

/**
 * A group-scoped action was attempted by somebody outside the group.
 *
 * Thrown by the Actions, never trusted to the screens — every one of these
 * is reachable from a public Livewire method.
 */
class NotGroupMember extends Exception
{
    public function __construct()
    {
        parent::__construct('This action is for members of the group.');
    }
}
