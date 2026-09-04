<?php

namespace App\Exceptions;

use Exception;

/**
 * A note was refused because the reader has spent their hour's worth.
 *
 * Feedback is read by a person, so a flood is a person's evening rather than
 * a database problem — five an hour is generous for anybody with something to
 * say and stingy for a script. Developer message only; the reader hears
 * Voice's `feedback.too_fast`.
 */
class FeedbackTooFast extends Exception
{
    public function __construct(public readonly int $availableIn)
    {
        parent::__construct("Sending feedback again is available in {$availableIn} seconds.");
    }
}
