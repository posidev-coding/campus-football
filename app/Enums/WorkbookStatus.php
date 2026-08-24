<?php

namespace App\Enums;

/**
 * Where an item sits on the board — and the columns of the Kanban, in order.
 *
 * `Dismissed` is the load-bearing one. The advisor re-reads real telemetry
 * every week and will propose the same thing again forever; dismissing an item
 * is how a human says "we know, and no". `WorkbookItem::propose()` refuses to
 * resurrect a dismissed row, so a decision survives the next run.
 */
enum WorkbookStatus: string
{
    case Inbox = 'inbox';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Inbox => 'Inbox',
            self::Planned => 'Planned',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Dismissed => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Inbox => 'gray',
            self::Planned => 'info',
            self::InProgress => 'warning',
            self::Done => 'success',
            self::Dismissed => 'danger',
        };
    }

    /**
     * The board's columns, left to right.
     *
     * Dismissed is NOT among them: it is an answer, not a stage, and a column
     * of things we have decided against is a column nobody reads. The table
     * surface filters to it instead.
     */
    public static function columns(): array
    {
        return [self::Inbox, self::Planned, self::InProgress, self::Done];
    }

    /** @return array<string, string> value => label, for a Filament select */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
