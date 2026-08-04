<?php

namespace App\Enums;

/**
 * An admin's override for a team's branded header, for the long tail no
 * formula wins. Null (no row value) means the TeamPalette ladder decides.
 *
 * Presets only, deliberately: every option maps to a combination the palette
 * already knows how to render, so an admin cannot configure an unreadable
 * header — only pick a different readable one.
 */
enum HeaderStyle: string
{
    /** Primary surface, white text — the sports default. */
    case White = 'white';

    /** Primary surface, secondary-color text (the Michigan maize look). */
    case SecondaryText = 'secondary-text';

    /** SECONDARY color as the surface, white text (the Arizona State look). */
    case SecondarySurface = 'secondary-surface';

    /** Primary surface, near-black text. The algorithm never chooses this. */
    case DarkText = 'dark-text';

    public function label(): string
    {
        return match ($this) {
            self::White => 'White text on primary',
            self::SecondaryText => 'Secondary color as text',
            self::SecondarySurface => 'Secondary color as surface',
            self::DarkText => 'Dark text on primary',
        };
    }
}
