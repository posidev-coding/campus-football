<?php

namespace App\Console\Commands;

use App\Actions\AutoPublishStandardSlate;
use App\Models\Contest;
use App\Models\Slate;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Cadence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The deadline sweep: any contest without a published board for the
 * current week, once the commissioner's deadline has passed, gets the
 * standard slate — the group is never hung out to dry with a blank week.
 *
 * DB-only, zero ESPN requests: the suggestion engine reads rows the syncs
 * already hold. Scheduled hourly; before the deadline every run is a
 * no-op, so the cost of the granularity is nothing.
 */
class PublishBoardsCommand extends Command
{
    protected $signature = 'pickem:publish-boards';

    protected $description = "Publish the standard slate for any contest whose commissioner missed the week's deadline";

    public function handle(CfbCalendar $calendar, AutoPublishStandardSlate $auto): int
    {
        $year = $calendar->currentYear();
        $weekId = $calendar->defaultWeekId($year);

        if ($weekId === null) {
            $this->info('No current week to publish for.');

            return self::SUCCESS;
        }

        $week = Week::query()->find($weekId);

        if ($week === null) {
            $this->info('No current week to publish for.');

            return self::SUCCESS;
        }

        /*
         * ONE SATURDAY, the one this pick'em week is playing. An ESPN week
         * can hold two — 2026's Week 1 has 8/29 and 9/5 — and sweeping the
         * week would publish a board for both, which is two weeks of picks
         * dropped on a group at once. `currentSaturday()` turns over on
         * Tuesday; the fallback is the week's primary card, for a clock
         * sitting outside this week entirely (a bye, the offseason).
         */
        $current = Cadence::currentSaturday();

        $saturday = collect(Cadence::saturdaysIn($week))
            ->first(fn ($day) => $day->toDateString() === $current->toDateString())
            ?? Cadence::saturdayOf($week);

        $deadline = $saturday === null ? null : Cadence::slateDeadline($saturday);

        if ($deadline === null || now()->lessThan($deadline)) {
            $this->info('Before the deadline; commissioners still have the floor.');

            return self::SUCCESS;
        }

        $due = Contest::query()
            ->where('season_year', $year)
            ->whereNotExists(fn ($q) => $q->selectRaw(1)
                ->from('slates')
                ->whereColumn('slates.contest_id', 'contests.id')
                ->where('slates.saturday', $saturday->toDateString())
                ->whereNot('slates.status', Slate::DRAFT))
            ->get();

        $published = 0;

        foreach ($due as $contest) {
            // One bad contest must not cost the rest of the league — the
            // same isolation every sync loop holds.
            try {
                if ($auto->handle($contest, $week, $saturday) !== null) {
                    $published++;
                } else {
                    Log::warning("Standard slate for contest {$contest->id} failed validation; left as draft.");
                }
            } catch (\Throwable $e) {
                Log::warning("Standard slate for contest {$contest->id} failed: {$e->getMessage()}");
            }
        }

        $this->info("Published {$published} standard board(s) of {$due->count()} due.");

        return self::SUCCESS;
    }
}
