<?php

namespace App\Enums;

/**
 * A specialty public room's identity: its marquee name, which mode's
 * engine it grades under, its seat cap, and the `contests.settings` its
 * rooms are stamped with at spawn. The engines never hear the word
 * "flavor" — they only read settings — so everything here is resolvable
 * to plain knobs, and a private league could someday carry the same ones
 * with no flavor at all.
 *
 * label() and blurb() are register-constant product vocabulary, the
 * ContestMode::label()/blurb() rule: the game is never described two
 * ways. The optional per-flavor zinger is Voice's job, in three
 * registers, on the card.
 */
enum LobbyFlavor: string
{
    case RankedAction = 'ranked_action';
    case UnderTheLights = 'under_lights';
    case TwoMinuteDrill = 'two_minute';
    case UpsetAlley = 'upset_alley';
    case BackPorch = 'back_porch';
    case SecShowdown = 'conf_sec';
    case BigTenBlitz = 'conf_b1g';
    case AccAction = 'conf_acc';
    case Big12Shootout = 'conf_b12';
    case Pac12AfterDark = 'conf_p12';

    /** The marquee room name — successors take Roman numerals. */
    public function label(): string
    {
        return match ($this) {
            self::RankedAction => 'Ranked Action',
            self::UnderTheLights => 'Under the Lights',
            self::TwoMinuteDrill => 'Two-Minute Drill',
            self::UpsetAlley => 'Upset Alley',
            self::BackPorch => 'Back Porch',
            self::SecShowdown => 'SEC Showdown',
            self::BigTenBlitz => 'Big Ten Blitz',
            self::AccAction => 'ACC Action',
            self::Big12Shootout => 'Big 12 Shootout',
            self::Pac12AfterDark => 'Pac-12 After Dark',
        };
    }

    public function mode(): ContestMode
    {
        return match ($this) {
            self::BackPorch => ContestMode::Woodshed,
            default => ContestMode::Classic,
        };
    }

    /** Seats — the flavored rooms never read the admin's standard cap. */
    public function cap(): int
    {
        return match ($this) {
            self::RankedAction => 50,
            self::TwoMinuteDrill, self::BackPorch => 10,
            default => 20,
        };
    }

    /**
     * The FIXED half of this flavor's settings — what every room of the
     * flavor carries regardless of the Saturday. Dynamic-size flavors get
     * `slate_size` frozen in at spawn (LobbyCatalog::resolve()); null
     * means pure mode defaults, per the settings column's law.
     */
    public function settings(): ?array
    {
        return match ($this) {
            self::RankedAction => ['slate_filter' => SlateFilter::Ranked->value],
            self::UnderTheLights => ['slate_size' => 8, 'slate_filter' => SlateFilter::Primetime->value],
            self::TwoMinuteDrill => ['slate_size' => 5],
            self::UpsetAlley => ['kicker' => 'underdog_ml', 'kicker_points' => 2],
            self::BackPorch => null,
            self::SecShowdown, self::BigTenBlitz, self::AccAction, self::Big12Shootout, self::Pac12AfterDark => [
                'slate_filter' => SlateFilter::Conference->value,
                'filter_conference' => $this->conference(),
            ],
        };
    }

    /**
     * Whether the slate is AS BIG AS THE SATURDAY ALLOWS — every game the
     * filter admits — rather than a fixed length.
     */
    public function dynamicSize(): bool
    {
        return $this->conference() !== null || $this === self::RankedAction;
    }

    /**
     * The lobby shelf this flavor is sold on. The conference family has
     * its own shelf; the two short-card flavors are the quick hits;
     * everything else is a spotlight room. A FLAVORLESS room is a house
     * room, which is why this lives on the flavor and the null case is
     * answered by the caller.
     */
    public function shelf(): LobbyShelf
    {
        return match (true) {
            $this->conference() !== null => LobbyShelf::Conference,
            $this === self::TwoMinuteDrill, $this === self::BackPorch => LobbyShelf::QuickHits,
            default => LobbyShelf::Spotlight,
        };
    }

    /** The conferences table abbreviation, for the conference family. */
    public function conference(): ?string
    {
        return match ($this) {
            self::SecShowdown => 'sec',
            self::BigTenBlitz => 'big10',
            self::AccAction => 'acc',
            self::Big12Shootout => 'big12',
            self::Pac12AfterDark => 'pac12',
            default => null,
        };
    }

    /** The conference's display name, for the shared zinger's :conference. */
    public function conferenceName(): ?string
    {
        return match ($this) {
            self::SecShowdown => 'SEC',
            self::BigTenBlitz => 'Big Ten',
            self::AccAction => 'ACC',
            self::Big12Shootout => 'Big 12',
            self::Pac12AfterDark => 'Pac-12',
            default => null,
        };
    }

    /**
     * The card's pitch, SIZED FROM THE ROOM. `$games` is the contest's own
     * frozen slate size; the fallback is this flavor's fixed size, then
     * its mode's default. A flavor can be seated smaller than its headline
     * number — Week 0 froze Upset Alley at eight — and a numbered pitch
     * that ignores that is the room lying about the card it deals. The
     * unnumbered pitches (dynamic sizes, the conference family) never had
     * the problem and stay as they are.
     */
    public function blurb(?int $games = null): string
    {
        $count = $games ?? $this->settings()['slate_size'] ?? $this->mode()->engine()->slateSize();

        return match ($this) {
            self::RankedAction => 'Every ranked team in action, one big card. 10 points a game.',
            self::UnderTheLights => $count.' night games, nothing before 7pm ET. 10 points a game.',
            self::TwoMinuteDrill => 'The flash card: '.$count.' games, in and out. 10 points a game.',
            self::UpsetAlley => $count.' games — and +2 on top when your dog covers AND wins outright.',
            self::BackPorch => "The founders' game at a ten-seat table. Lock one call, beat the Bear.",
            self::SecShowdown => 'Every game with an SEC team in it. 10 points a game.',
            self::BigTenBlitz => 'Every game with a Big Ten team in it. 10 points a game.',
            self::AccAction => 'Every game with an ACC team in it. 10 points a game.',
            self::Big12Shootout => 'Every game with a Big 12 team in it. 10 points a game.',
            self::Pac12AfterDark => 'Every game with a Pac-12 team in it. 10 points a game.',
        };
    }

    /**
     * The Voice key for the card's optional zinger — per-flavor first,
     * with the conference family sharing one key and a :conference
     * replacement. Renders guarded `!== ''`, so an unwritten key is a
     * quieter card and never a hole.
     */
    public function zingerKey(): string
    {
        return $this->conference() !== null
            ? 'lobby.flavor.zinger.conference'
            : 'lobby.flavor.zinger.'.$this->value;
    }
}
