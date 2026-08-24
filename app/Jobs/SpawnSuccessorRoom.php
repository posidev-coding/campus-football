<?php

namespace App\Jobs;

use App\Actions\SpawnPublicContest;
use App\Models\Group;
use App\Models\Slate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Provision the successor to a room that just FILLED.
 *
 * Split out of JoinGroup: spawning is multi-hundred-ms of writes (a group,
 * a contest, a seeded slate), and it ran INLINE in the join request — the
 * person taking the last seat paid for the next room's construction. The
 * join keeps the `filled_at` claim (so exactly one joiner dispatches this,
 * however many land at the cap together); this job does the building, and
 * the hourly `pickem:open-lobbies` sweep is the belt if it is ever lost.
 * The visible trade: the next room can appear seconds after the fill
 * instead of in the same breath.
 */
class SpawnSuccessorRoom implements ShouldQueue
{
    use Queueable;

    /** Must stay below the queue's `retry_after` (90s). */
    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(public int $roomId) {}

    public function handle(SpawnPublicContest $spawn): void
    {
        $room = Group::query()->find($this->roomId);

        if ($room === null) {
            return;
        }

        $contest = $room->contests()->first();
        $week = $room->week()->first();

        if ($contest === null || $week === null) {
            return;
        }

        /*
         * The successor inherits the filled room's WHOLE identity: flavor
         * (and with it the cap and settings) and the CARD — a filled
         * Week 0 room must respawn on Week 0, not on the split week's
         * main Saturday. value() hydrates through the date cast — take
         * the plain calendar date and re-pin it in ET, never through a
         * timezone conversion.
         */
        $saturday = Slate::query()
            ->where('contest_id', $contest->id)
            ->where('week_id', $week->id)
            ->value('saturday')?->format('Y-m-d');

        $spawn->handle(
            $contest->mode,
            $week,
            $saturday === null ? null : CarbonImmutable::parse($saturday, config('cfb.timezone'))->startOfDay(),
            $room->flavorEnum(),
        );
    }
}
