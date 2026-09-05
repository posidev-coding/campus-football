<?php

namespace App\Exceptions;

use Exception;

/**
 * A direct invite was addressed to somebody it may not be addressed to.
 *
 * One exception for every refusal, because the screen has one answer: the
 * reasons (not a private group, a stranger you have never played with, an
 * account with no handle to name) differ to the Action and not to the
 * reader, and spelling them out would tell a sender things about a person
 * they are not entitled to know — "that account exists but has no handle"
 * is itself a fact about somebody.
 */
class CannotInvite extends Exception
{
    public function __construct()
    {
        parent::__construct('That invite cannot be sent.');
    }
}
