<?php

namespace App\Exceptions;

use Exception;

/**
 * A commissioner-only group action was attempted by a plain member.
 *
 * Thrown by the Actions rather than trusted to the screens, so a public
 * Livewire method can never be talked into running it.
 */
class NotGroupCommissioner extends Exception
{
    public function __construct()
    {
        parent::__construct('Only the group\'s commissioner can do this.');
    }
}
