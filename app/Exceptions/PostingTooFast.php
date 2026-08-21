<?php

namespace App\Exceptions;

use Exception;

/**
 * A post was refused because the author is inside the cooldown window.
 *
 * The Conversation has no edit and no delete-for-everyone, so a flood is
 * permanent in a way a pick never is — the limiter is the only thing
 * standing between a bad night and a thread nobody can read. Developer
 * message only; the reader hears Voice's `talk.too_fast`.
 */
class PostingTooFast extends Exception
{
    public function __construct(public readonly int $availableIn)
    {
        parent::__construct("Posting again is available in {$availableIn} seconds.");
    }
}
