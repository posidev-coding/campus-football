<?php

namespace App\Enums;

/**
 * What kind of work a workbook item is.
 *
 * Bounded, and the advisor is handed this list rather than inventing labels —
 * a free-text category grows a hundred near-synonyms in a month and makes the
 * board unfilterable. Same reasoning as UxSignal's vocabulary.
 */
enum WorkbookCategory: string
{
    case Bug = 'bug';
    case Feature = 'feature';
    case Performance = 'performance';
    case Ux = 'ux';
    case Data = 'data';
    case Ops = 'ops';
    case TechDebt = 'tech-debt';

    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Bug',
            self::Feature => 'Feature',
            self::Performance => 'Performance',
            self::Ux => 'UX',
            self::Data => 'Data',
            self::Ops => 'Ops',
            self::TechDebt => 'Tech debt',
        };
    }

    /** Filament's badge palette. Bugs read red, everything else is calmer. */
    public function color(): string
    {
        return match ($this) {
            self::Bug => 'danger',
            self::Performance => 'warning',
            self::Feature => 'success',
            self::Ux => 'info',
            self::Data, self::Ops => 'primary',
            self::TechDebt => 'gray',
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
