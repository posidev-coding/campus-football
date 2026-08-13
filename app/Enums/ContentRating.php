<?php

namespace App\Enums;

/**
 * How hard the app is allowed to roast a given user.
 *
 * Replaces the old `TrashTalkIntensity` — same axis, borrowed vocabulary.
 * "Mild / Locker Room / No Holds Barred" needed explaining; a film rating does
 * not, and it is the same shorthand the App Store age rating uses, which is the
 * other place this setting has to hold up.
 *
 * Whatever the level, roasts target picks, teams, and performance — never a
 * person's identity. That line is what keeps this funny instead of a liability.
 */
enum ContentRating: string
{
    /** Clean. Nothing you would mind a kid reading over your shoulder. */
    case Pg = 'pg';

    /** The default. How a group chat actually talks. */
    case Pg13 = 'pg13';

    /** Unfiltered. For groups that have asked for it. */
    case R = 'r';

    public function label(): string
    {
        return match ($this) {
            self::Pg => 'PG',
            self::Pg13 => 'PG-13',
            self::R => 'R',
        };
    }

    /**
     * The plain-English name beside the rating.
     *
     * The film rating is the shorthand people scan; this says what it means in
     * the app's own terms. "No Holds Barred" was the old name for the top tier
     * and is out — it is wrestling jargon that not everyone reads the same way.
     * "Anything Goes" needs no explaining.
     */
    public function subLabel(): string
    {
        return match ($this) {
            self::Pg => 'Mild',
            self::Pg13 => 'Locker Room',
            self::R => 'Anything Goes',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pg => "Light ribbing. Nothing you'd mind a kid reading over your shoulder.",
            self::Pg13 => 'How your group chat actually talks. Occasional mild profanity, real insults.',
            self::R => 'Nothing held back. No sympathy after an 0-5 week.',
        };
    }

    /** Levels at or below this one, used when selecting a taunt. */
    public function includes(): array
    {
        return match ($this) {
            self::Pg => [self::Pg],
            self::Pg13 => [self::Pg, self::Pg13],
            self::R => [self::Pg, self::Pg13, self::R],
        };
    }
}
