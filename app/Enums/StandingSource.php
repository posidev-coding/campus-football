<?php

namespace App\Enums;

/**
 * Standings are stored twice per team-season and never merged. Keeping the two
 * apart is what lets the reconciler detect a silent feed regression instead of
 * quietly serving wrong records.
 */
enum StandingSource: string
{
    /** ESPN's published standings — authoritative. */
    case Espn = 'espn';

    /** Derived from our own completed games — the cross-check. */
    case Computed = 'computed';
}
