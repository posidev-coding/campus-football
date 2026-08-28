<?php

namespace App\Enums;

/**
 * How big the work is — not how badly it wants doing.
 *
 * Three levels, and that does NOT contradict `WorkbookSeverity`'s "an
 * odd-numbered scale grows a middle everything drifts into". A medium SIZE is a
 * real answer: most work is a day, and saying so is information. A medium
 * PRIORITY is a place to hide, which is why severity has four and this has
 * three. Do not "fix" this to four.
 *
 * NULL is also a real answer here, and it means NOT SIZED. Nothing defaults to
 * Medium — a card nobody has sized must read as unsized, or the ready queue
 * fills with work whose cost was guessed by a cast.
 */
enum WorkbookEffort: string
{
    case Small = 's';
    case Medium = 'm';
    case Large = 'l';

    public function label(): string
    {
        return match ($this) {
            self::Small => 'Small',
            self::Medium => 'Medium',
            self::Large => 'Large',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Small => 'gray',
            self::Medium => 'info',
            self::Large => 'warning',
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
