<?php

namespace App\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * Raised when a user tries to follow more teams than they are allowed.
 *
 * An exception rather than a silently-ignored write, because the two are
 * indistinguishable to a caller: a follow that quietly does nothing produces a
 * button that looks like it worked and a news tab that never fills in. Every
 * caller either handles this and says something, or fails loudly in a way that
 * gets noticed.
 *
 * The message here is for logs and for developers. What a USER reads comes from
 * `Voice`, because it changes with their content rating — copy baked into an
 * exception can only ever speak in one register.
 */
class FollowLimitReached extends RuntimeException
{
    public function __construct(public readonly int $limit = User::MAX_FOLLOWED_TEAMS)
    {
        parent::__construct("Follow limit of {$limit} reached.");
    }
}
