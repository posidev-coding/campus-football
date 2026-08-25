<?php

namespace App\Enums;

/**
 * How much we actually know about where College GameDay is broadcasting from.
 *
 * Three states and no fourth, because the interesting one is the FIRST:
 * `Unknown` is a real answer that the card says out loud. ESPN announces the
 * site about a week ahead, so early-week emptiness is normal — and a feature
 * whose whole job is producing a location is exactly where "never write a
 * default when data is missing" is most tempting to break.
 */
enum GamedayStatus: string
{
    /** Nothing resolved, or everything resolved failed a guard. */
    case Unknown = 'unknown';

    /** Passed every guard, awaiting a human's nod. Renders — it survived the contradiction check. */
    case Proposed = 'proposed';

    /** A human said yes. Never overwritten by a later run. */
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not yet announced',
            self::Proposed => 'Proposed',
            self::Confirmed => 'Confirmed',
        };
    }

    /** Is there a site worth putting on the home page? */
    public function isKnown(): bool
    {
        return $this !== self::Unknown;
    }
}
