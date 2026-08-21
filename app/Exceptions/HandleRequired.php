<?php

namespace App\Exceptions;

use Exception;

/**
 * A pick or a post was attempted by a user who has not claimed a handle.
 *
 * The handle is claimed at the first participation moment — the seam
 * product.md reserved — and the Actions refuse handleless writes so no
 * screen can route around the claim. The screen catching this shows the
 * claim affordance; the user-facing copy is Voice's.
 */
class HandleRequired extends Exception
{
    public function __construct()
    {
        parent::__construct('A claimed handle is required before participating.');
    }
}
