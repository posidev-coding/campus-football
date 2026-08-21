<?php

namespace App\Exceptions;

use Exception;

/**
 * A delete was attempted by somebody who is neither the post's author, the
 * commissioner of the group it sits in, nor an app admin.
 *
 * Thrown by the Action, never trusted to the screen — the delete button is
 * presentation and this is enforcement.
 */
class CannotModeratePost extends Exception
{
    public function __construct()
    {
        parent::__construct('This post is not yours to delete.');
    }
}
