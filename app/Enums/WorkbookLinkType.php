<?php

namespace App\Enums;

/**
 * How one issue relates to another.
 *
 * Five names, THREE of them storable. `blocked_by` and `duplicated_by` are the
 * inverses — they are how a human says it out loud, and they are never written
 * to a row: `A blocked_by B` is stored as `B blocks A`.
 *
 * That is the whole design. One directed row, never a mirrored pair: a mirror
 * doubles every write and every delete, and the first caller that does one half
 * leaves a half-link no unique index can even describe as broken — the same
 * argument `.ai/rules/support.md` makes for `FollowTeam`. It also carries zero
 * information, because the inverse is a pure function of the type, which is
 * exactly what `inverse()` is.
 */
enum WorkbookLinkType: string
{
    case Blocks = 'blocks';

    case BlockedBy = 'blocked_by';

    case RelatesTo = 'relates_to';

    case Duplicates = 'duplicates';

    case DuplicatedBy = 'duplicated_by';

    public function inverse(): self
    {
        return match ($this) {
            self::Blocks => self::BlockedBy,
            self::BlockedBy => self::Blocks,
            // Symmetric, and its own inverse. Which is why the action stores it
            // with the lower id first — otherwise `A relates_to B` and
            // `B relates_to A` are two rows the unique index happily accepts.
            self::RelatesTo => self::RelatesTo,
            self::Duplicates => self::DuplicatedBy,
            self::DuplicatedBy => self::Duplicates,
        };
    }

    /** The three that reach a row. The other two are stored as their inverse. */
    public function isStorable(): bool
    {
        return in_array($this, [self::Blocks, self::RelatesTo, self::Duplicates], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Blocks => 'Blocks',
            self::BlockedBy => 'Blocked by',
            self::RelatesTo => 'Relates to',
            self::Duplicates => 'Duplicates',
            self::DuplicatedBy => 'Duplicated by',
        };
    }

    /** @return array<string, string> value => label, for a Filament select */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
