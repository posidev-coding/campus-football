<?php

namespace App\Enums;

/**
 * How hard the app is allowed to roast a given user.
 *
 * A HEAT SCALE — Mild, Medium, Spicy. The top rung is "Spicy" rather than a
 * "Nuclear" or a "Ghost Pepper" on purpose: an over-promising top tier is the
 * exact failure being repaired here, and it would land the same way in any
 * vocabulary. The film ratings this wore until
 * 2026-08-31 were reversed after the registers were measured against each
 * other: across all 239 Voice families, PG and PG-13 contain no profanity at
 * all and R contains exactly one mild word. The registers differ in ATTITUDE,
 * not in vocabulary, and the tiers get longer and more merciless rather than
 * more explicit — so a film rating described a scale this app is not allowed
 * to deliver. It over-promised to everyone who chose R, warned off the people
 * who would most enjoy the best-written register, and self-declared an
 * "R / Anything Goes" mode to App Store review over content containing one
 * "damn": the shorthand borrowed to satisfy the age rating was running in
 * exactly the wrong direction. A heat scale needs no explaining either, and
 * claims nothing about maturity.
 *
 * THE BACKING VALUES STAY `pg`/`pg13`/`r`, deliberately. They are the array
 * keys of all 239 families in App\Support\Voice and the stored column on
 * `users`, so renaming them to match the labels would be a migration plus a
 * rewrite of every authored line for zero user-visible gain. The names below
 * are display; the values are data.
 *
 * Whatever the level, roasts target picks, teams, and performance — never a
 * person's identity. That line is what keeps this funny instead of a liability,
 * and it is also the ceiling: no register may be written up to its label.
 */
enum ContentRating: string
{
    /** Clean. Nothing you would mind a kid reading over your shoulder. */
    case Pg = 'pg';

    /** The default. How a group chat actually talks. */
    case Pg13 = 'pg13';

    /** Merciless about the picks. Never about the person. */
    case R = 'r';

    public function label(): string
    {
        return match ($this) {
            self::Pg => 'Mild',
            self::Pg13 => 'Medium',
            self::R => 'Spicy',
        };
    }

    /**
     * The plain-English name beside the heat.
     *
     * The scale is the shorthand people scan; this says what it means in the
     * app's own terms. "Locker Room" survived the 2026-08-31 relabel because it
     * is the one of the three that always described the writing rather than a
     * rating board's idea of it.
     */
    public function subLabel(): string
    {
        return match ($this) {
            self::Pg => 'Light Ribbing',
            self::Pg13 => 'Locker Room',
            self::R => 'No Mercy',
        };
    }

    /**
     * What the level actually changes.
     *
     * Honest about the ceiling: the old PG-13 line promised "occasional mild
     * profanity" the register has never contained, and the old R line promised
     * "nothing held back" when the roast-the-pick law holds plenty back. A
     * description that writes a check the copy cannot cash is how the setting
     * came to feel broken.
     */
    public function description(): string
    {
        return match ($this) {
            self::Pg => "Clean, still funny. Nothing you'd mind a kid reading over your shoulder.",
            self::Pg13 => 'How your group chat actually talks. Real insults, clean language.',
            self::R => 'No sympathy after an 0-5 week. Your picks get roasted — never you.',
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
