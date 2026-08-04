<?php

namespace App\Enums;

/**
 * Where in the college football calendar we currently are.
 *
 * This matters because "the current season" is genuinely ambiguous for large
 * parts of the year. In August the chronological season is the one about to
 * start, but the only season with results, standings or polls is the one that
 * just finished — and a dropdown that defaults to the wrong one leaves the user
 * staring at an empty screen.
 */
enum SeasonPhase: string
{
    /** Between the previous postseason and the upcoming kickoff. */
    case Preseason = 'preseason';

    case Regular = 'regular';

    /** Conference championships, bowls, and the playoff. */
    case Postseason = 'postseason';

    /** Deep offseason — no season starting soon. */
    case Offseason = 'offseason';

    public function label(): string
    {
        return match ($this) {
            self::Preseason => 'Preseason',
            self::Regular => 'Regular Season',
            self::Postseason => 'Postseason',
            self::Offseason => 'Offseason',
        };
    }

    /** Whether games are actively being played. */
    public function isLive(): bool
    {
        return $this === self::Regular || $this === self::Postseason;
    }
}
