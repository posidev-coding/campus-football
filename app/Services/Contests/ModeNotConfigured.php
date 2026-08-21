<?php

namespace App\Services\Contests;

use RuntimeException;

/**
 * A rule question was asked of a mode whose rules have not arrived.
 *
 * The Woodshed ships as a stub — its rules are with the league's founders —
 * and this exception is what "stub" means in practice: any code path that
 * would need those rules fails loudly here rather than inventing them,
 * which is the no-defaults rule applied to game design. The message is a
 * developer string; nothing user-facing renders it, because available()
 * keeps the mode out of every picker until the rules land.
 */
class ModeNotConfigured extends RuntimeException
{
    public static function for(string $mode): self
    {
        return new self("The {$mode} mode's rules are not configured yet.");
    }
}
