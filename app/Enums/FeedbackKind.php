<?php

namespace App\Enums;

/**
 * What kind of note a reader sent.
 *
 * Bounded for the reason WorkbookCategory is: the triage table filters on it,
 * and a free-text kind grows a hundred near-synonyms in a month. Four is a
 * product decision rather than a taxonomy — a reader tapping one of four chips
 * at 390px is choosing, not filling in a form, and a fifth chip is the one
 * that starts clipping.
 */
enum FeedbackKind: string
{
    case Bug = 'bug';
    case Idea = 'idea';
    case Confused = 'confused';
    case Praise = 'praise';

    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Bug',
            self::Idea => 'Idea',
            self::Confused => 'Confused',
            self::Praise => 'Praise',
        };
    }

    /** Filament's badge palette. Bugs read red, the rest are calmer. */
    public function color(): string
    {
        return match ($this) {
            self::Bug => 'danger',
            self::Idea => 'success',
            self::Confused => 'warning',
            self::Praise => 'info',
        };
    }

    /**
     * The workbook category a note of this kind files under — or null when
     * it is not work. Praise goes on the fridge, not the board, and a
     * category it does not have is how the file action knows to stay hidden.
     */
    public function workbookCategory(): ?WorkbookCategory
    {
        return match ($this) {
            self::Bug => WorkbookCategory::Bug,
            self::Idea => WorkbookCategory::Feature,
            self::Confused => WorkbookCategory::Ux,
            self::Praise => null,
        };
    }

    /** @return array<string, string> value => label, for the chips and a Filament select */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
