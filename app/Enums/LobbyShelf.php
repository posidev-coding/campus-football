<?php

namespace App\Enums;

/**
 * A shelf in the lobby: the four groupings the open rooms are sold
 * under, in the order they are displayed.
 *
 * Thirteen rooms is more than a reader can hold in one list, so the
 * lobby sells them in named blocks — the case order IS the display
 * order. Headings stay PLAIN, in every register: people navigate by
 * them, and a shelf whose name is a joke is a shelf nobody can find.
 * The register copy rides `voiceKey()` as an optional line beneath.
 */
enum LobbyShelf: string
{
    case House = 'house';
    case QuickHits = 'quick_hits';
    case Spotlight = 'spotlight';
    case Conference = 'conference';

    /** The heading. A fact, never a joke — and never the word "games". */
    public function heading(): string
    {
        return match ($this) {
            self::House => 'House rooms',
            self::QuickHits => 'Quick hits',
            self::Spotlight => 'Spotlight',
            self::Conference => 'Conference rooms',
        };
    }

    /**
     * The optional Voice line under the heading. Render-guarded `!== ''`,
     * so an unwritten register is a quieter shelf and never a hole.
     */
    public function voiceKey(): string
    {
        return 'lobby.shelf.'.$this->value;
    }
}
