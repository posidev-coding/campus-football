<?php

namespace App\Exceptions;

use Exception;

/**
 * A group merge refused before it wrote a row: a group that is not private,
 * a survivor with nobody to run it, or a week still in flight somewhere in
 * the set — every reason at once, so one dry run answers everything.
 *
 * Developer messages only. The one reader is whoever runs
 * `pickem:merge-groups` at a console, and the command prints each reason on
 * its own line; nothing here ever reaches a member.
 */
class MergeBlocked extends Exception
{
    /**
     * @param  list<string>  $reasons
     */
    private function __construct(public readonly array $reasons)
    {
        parent::__construct('The merge is blocked: '.implode(' ', $reasons));
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function because(array $reasons): self
    {
        return new self($reasons);
    }
}
