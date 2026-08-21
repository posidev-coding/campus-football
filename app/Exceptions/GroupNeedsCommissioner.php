<?php

namespace App\Exceptions;

use Exception;

/**
 * The commissioner tried to leave a group that still has members.
 *
 * Somebody has to run the place — the commissioner leaves last. Developer
 * message only; the user-facing line is Voice's 'groups.leave.commissioner'.
 */
class GroupNeedsCommissioner extends Exception
{
    public function __construct()
    {
        parent::__construct('The commissioner cannot leave while the group has members.');
    }
}
