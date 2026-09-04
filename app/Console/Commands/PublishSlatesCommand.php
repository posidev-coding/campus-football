<?php

namespace App\Console\Commands;

use App\Actions\AutoPublishStandardSlate;
use App\Console\Concerns\TracksFeedRun;
use App\Models\Contest;
use App\Models\Group;
use App\Models\Slate;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Cadence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The deadline sweep: any contest without a published slate for the
 * current week, once the commissioner's deadline has passed, gets the
 * standard slate — the group is never hung out to dry with a blank week.
 *
 * DB-only, zero ESPN requests: the suggestion engine reads rows the syncs
 * already hold. Scheduled hourly; before the deadline every run is a
 * no-op, so the cost of the granularity is nothing.
 */
class PublishSlatesCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'pickem:publish-slates';

    protected $description = "Publish the standard slate for any contest whose commissioner missed the week's deadline";

    public function handle(CfbCalendar $calendar, AutoPublishStandardSlate $auto): int
    {
        $year = $calendar->currentYear();

        /*
         * EVERY TICK IS RECORDED, including — especially — the quiet ones.
         * The sweep runs hourly all week and only the hours after the
         * commissioner's deadline have anything to publish, so the no-op
         * below is the path nearly every run takes. Without a row the
         * schedule panel cannot tell "ran, nobody was due" from "the worker
         * is dead", which is the whole reason this sweep reached the panel.
         * Zero is a measured fact here, never a substituted default — so
         * every guard returns from INSIDE the closure, never above it.
         *
         * This spends NO ESPN request, so the trait's counter reads 0 on
         * every row it writes. That is the truth and it stays 0.
         */
        $this->trackRun('publish-slates', $year, function () use ($auto, $calendar, $year): int {
            $weekId = $calendar->defaultWeekId($year);

            if ($weekId === null) {
                $this->info('No current week to publish for.');

                return 0;
            }

            $week = Week::query()->find($weekId);

            if ($week === null) {
                $this->info('No current week to publish for.');

                return 0;
            }

            /*
             * ONE SATURDAY, the one this pick'em week is on. An ESPN week can
             * hold two — 2026's Week 1 has 8/29 and 9/5 — and sweeping the
             * week would publish a slate for both, which is two weeks of
             * picks dropped on a group at once. activeSaturday() is the same
             * answer the lobby, the stocking sweep and the preflight read,
             * cards in order.
             */
            $saturday = Cadence::activeSaturday($week);

            $deadline = $saturday === null ? null : Cadence::slateDeadline($saturday);

            if ($deadline === null || now()->lessThan($deadline)) {
                $this->info('Before the deadline; the commissioners still have it.');

                return 0;
            }

            $due = Contest::query()
                ->where('season_year', $year)
                /*
                 * PRIVATE groups only. House rooms are born WITH a published
                 * slate at spawn and die with their Saturday — sweeping them
                 * would stamp a fresh slate into every dead room weekly, and
                 * a flavored contest's frozen settings (a Week 0 seven, a
                 * ranked card's size) would mis-size every one of them.
                 */
                ->whereHas('group', fn ($g) => $g->where('kind', '!=', Group::KIND_LOBBY))
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

            $this->info("Published {$published} standard slate(s) of {$due->count()} due.");

            // The slates this pass actually wrote. A contest whose standard
            // card failed validation is counted by nobody: it is still a
            // draft, and the warning above is where it is reported.
            return $published;
        });

        return self::SUCCESS;
    }
}
