<?php

namespace App\Enums;

/**
 * The polls this app carries, keyed by a stable slug of our own.
 *
 * Deliberately NOT keyed on ESPN's `type` field, which is not unique: AFCA
 * Division II (id 11) and Division III (id 12) both report `type: "afca"`, so
 * keying on it silently merged two different polls into one — 800 rows under a
 * single key where every other poll had 400.
 *
 * ESPN's numeric ranking id IS unique, so that is what the mapping turns on.
 */
enum Poll: string
{
    case Ap = 'ap';
    case Coaches = 'coaches';
    case Cfp = 'cfp';
    case CfpSeedings = 'cfp-seedings';
    case Fcs = 'fcs';
    case DivisionII = 'afca-dii';
    case DivisionIII = 'afca-diii';

    public static function fromEspnId(int|string $id): ?self
    {
        return match ((int) $id) {
            1 => self::Ap,
            2 => self::Coaches,
            11 => self::DivisionII,
            12 => self::DivisionIII,
            20 => self::Fcs,
            21 => self::Cfp,
            22 => self::CfpSeedings,
            default => null,
        };
    }

    public function espnId(): int
    {
        return match ($this) {
            self::Ap => 1,
            self::Coaches => 2,
            self::DivisionII => 11,
            self::DivisionIII => 12,
            self::Fcs => 20,
            self::Cfp => 21,
            self::CfpSeedings => 22,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ap => 'AP Top 25',
            self::Coaches => 'Coaches Poll',
            self::Cfp => 'CFP Rankings',
            self::CfpSeedings => 'CFP Seedings',
            self::Fcs => 'FCS Coaches',
            self::DivisionII => 'AFCA Div II',
            self::DivisionIII => 'AFCA Div III',
        };
    }

    /**
     * The polls an FBS audience actually cares about, in the order a rankings
     * page should offer them.
     *
     * @return list<self>
     */
    public static function major(): array
    {
        return [self::Cfp, self::Ap, self::Coaches];
    }

    /**
     * Which division tab this poll sits under on /rankings, or null for a
     * poll the screen does not carry at all.
     *
     * 'fbs' and 'fcs' are the Scope vocabulary Standings' plate already
     * speaks. The AFCA Division II and III polls are EXCLUDED deliberately —
     * this is a Division I app, and a small-college tab spent a third of the
     * plate on lists nobody here reads. Their rows still sync and store; the
     * screen just never offers them.
     */
    public function division(): ?string
    {
        return match ($this) {
            self::Fcs => 'fcs',
            self::DivisionII, self::DivisionIII => null,
            default => 'fbs',
        };
    }

    /**
     * One division's polls, in the order a rankings screen should prefer
     * them — majors first for FBS, matching availablePolls()' presentation.
     *
     * @return list<self>
     */
    public static function inDivision(string $division): array
    {
        $ordered = [...self::major(), self::CfpSeedings, self::Fcs];

        return array_values(array_filter($ordered, fn (self $poll) => $poll->division() === $division));
    }

    /**
     * The first regular-season week this poll can appear in.
     *
     * AP and Coaches publish a preseason poll and run all season. The CFP
     * committee does not release its first rankings until week 11 — verified
     * live against 2025, where week 10 has five polls and week 11 has six.
     */
    public function firstWeek(): int
    {
        return match ($this) {
            self::Cfp, self::CfpSeedings => 11,
            default => 1,
        };
    }

    public function isCfp(): bool
    {
        return $this === self::Cfp || $this === self::CfpSeedings;
    }
}
