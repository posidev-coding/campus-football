<?php

namespace App\Enums;

/**
 * How hard the app is allowed to roast a given user.
 *
 * This is opt-down rather than opt-in: the default is `LockerRoom`, because a
 * sanitised default would undercut the whole voice of the product. Whatever the
 * level, roasts target picks, teams, and performance — never a person's
 * identity. That line is what keeps this funny instead of a liability, and it
 * is also what keeps the mobile build inside its App Store age rating.
 */
enum TrashTalkIntensity: string
{
    /** Gentle ribbing. Safe for the easily wounded. */
    case Mild = 'mild';

    /** The default. Profanity, real insults, movie-quote abuse. */
    case LockerRoom = 'locker_room';

    /** Unfiltered. For groups that have asked for it. */
    case NoHoldsBarred = 'no_holds_barred';

    public function label(): string
    {
        return match ($this) {
            self::Mild => 'Mild',
            self::LockerRoom => 'Locker Room',
            self::NoHoldsBarred => 'No Holds Barred',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Mild => "Light jabs only. We'll go easy on you.",
            self::LockerRoom => 'How your group chat actually talks. Recommended.',
            self::NoHoldsBarred => 'You asked for this. No sympathy after a 2-13 week.',
        };
    }

    /** Levels at or below this one, used when selecting a taunt. */
    public function includes(): array
    {
        return match ($this) {
            self::Mild => [self::Mild],
            self::LockerRoom => [self::Mild, self::LockerRoom],
            self::NoHoldsBarred => [self::Mild, self::LockerRoom, self::NoHoldsBarred],
        };
    }
}
