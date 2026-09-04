<?php

namespace App\Console\Commands;

use App\Actions\SpawnPublicContest;
use App\Console\Concerns\TracksFeedRun;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Models\Group;
use App\Models\Slate;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Cadence;
use App\Support\LobbyCatalog;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The lobby's shelf-stocking sweep: at least ONE open public room for
 * every catalog entry the current Saturday can support.
 *
 * The join hook spawns the next room the instant one fills — this sweep
 * is the belt under those suspenders: the very first rooms of a week
 * (nothing to fill yet), a spawn that failed mid-provision, a week whose
 * inventory settled early. Failures are isolated per entry: Hail Mary's
 * empty shelf must not keep Wishbone's stocked.
 *
 * The target is a SATURDAY, not a week — a split opening week holds two
 * cards, and the lobby stocks the one the pick'em clock is on. An entry
 * the Saturday cannot support (Week 0 cannot seat a fifteen-game mode)
 * is the spawner's feasibility gate declining quietly, not a failure.
 */
class OpenLobbiesCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'pickem:open-lobbies';

    protected $description = 'Keep at least one open public room per catalog entry for the current Saturday';

    public function handle(CfbCalendar $calendar, SpawnPublicContest $spawn): int
    {
        $year = $calendar->currentYear();

        /*
         * EVERY TICK IS RECORDED, and the FULL shelf is the common tick. The
         * sweep runs hourly and the join hook usually restocks a room the
         * instant one fills, so most passes walk the catalog, find every
         * entry already open and spawn nothing. That pass and a dead worker
         * used to render identically on the schedule panel. Zero is a
         * measured fact, never a substituted default, so the guards below
         * return from INSIDE the closure rather than bailing above it.
         *
         * No ESPN request is spent here, so the trait's counter reads 0 on
         * every row it writes, correctly.
         */
        $this->trackRun('open-lobbies', $year, function () use ($calendar, $spawn, $year): int {
            $weekId = $calendar->defaultWeekId($year);

            if ($weekId === null || ($week = Week::find($weekId)) === null) {
                $this->info('No current week to stock.');

                return 0;
            }

            $saturday = Cadence::activeSaturday($week);

            if ($saturday === null) {
                $this->info('No Saturday to stock for.');

                return 0;
            }

            $spawned = 0;

            foreach (LobbyCatalog::entries() as $entry) {
                if (! $entry['mode']->available()) {
                    continue;
                }

                /*
                 * A CAUGHT ENTRY STILL LEAVES THE PASS COMPLETE, deliberately.
                 * The catch is the isolation this sweep is built on — Hail
                 * Mary's empty shelf must not keep Wishbone's stocked — and the
                 * command exits SUCCESS, so a `failed` row here would put the
                 * ledger at odds with the scheduler's exit code and the Cloud
                 * failure signal. TracksFeedRun rethrows precisely to keep
                 * those three saying the same thing; `failed` means the
                 * exception escaped, and one that was caught did not.
                 *
                 * The warning below is where a caught entry is reported, and
                 * it carries the mode, so the shelf that did not get stocked
                 * is named in the log rather than implied by a count.
                 */
                try {
                    if ($this->hasOpenRoom($entry['mode'], $week, $saturday, $entry['flavor'])) {
                        continue;
                    }

                    if ($spawn->handle($entry['mode'], $week, $saturday, $entry['flavor']) !== null) {
                        $spawned++;
                    }
                } catch (Throwable $e) {
                    Log::warning('pickem:open-lobbies failed for a catalog entry', [
                        'mode' => $entry['mode']->value,
                        'flavor' => $entry['flavor']?->value,
                        'week_id' => $week->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->info("Spawned {$spawned} room(s) for {$saturday->format('Y-m-d')}.");

            // The rooms this pass actually opened. An entry the Saturday
            // cannot support is the spawner declining, not a room.
            return $spawned;
        });

        return self::SUCCESS;
    }

    private function hasOpenRoom(ContestMode $mode, Week $week, CarbonInterface $saturday, ?LobbyFlavor $flavor): bool
    {
        return Group::query()
            ->where('kind', Group::KIND_LOBBY)
            ->where('week_id', $week->id)
            ->where('flavor', $flavor?->value)
            ->whereNull('filled_at')
            ->whereHas('contests', fn ($q) => $q
                ->where('mode', $mode)
                ->whereHas('slates', fn ($s) => $s
                    ->where('saturday', $saturday->format('Y-m-d'))
                    ->where('status', Slate::PUBLISHED)))
            ->withCount('memberships')
            ->get()
            ->contains(fn (Group $room) => $room->member_cap === null
                || $room->memberships_count < $room->member_cap);
    }
}
