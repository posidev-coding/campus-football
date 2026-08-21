<?php

namespace App\Exceptions;

use Exception;

/**
 * The mode pivot was refused by a LEAGUE rule, not by authority: either
 * the group already spent its one change this season, or a published week
 * is mid-flight and the engine would grade it under the wrong rules.
 *
 * Developer message only; the screen maps `$reason` to the Voice family
 * `mode.change.blocked.*` so the user hears it in their own register.
 */
class ModeChangeBlocked extends Exception
{
    public const USED = 'used';

    public const IN_FLIGHT = 'in_flight';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyUsed(): self
    {
        return new self(self::USED, 'The group already changed its mode this season.');
    }

    public static function slateInFlight(): self
    {
        return new self(self::IN_FLIGHT, 'A published slate is still in flight; the engine reads mode at grade time.');
    }
}
