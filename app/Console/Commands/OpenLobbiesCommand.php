<?php

namespace App\Console\Commands;

use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Models\Group;
use App\Models\Slate;
use App\Models\Week;
use App\Services\CfbCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The lobby's shelf-stocking sweep: at least ONE open public room per
 * available mode for the current week, always.
 *
 * The join hook spawns the next room the instant one fills — this sweep
 * is the belt under those suspenders: the very first rooms of a week
 * (nothing to fill yet), a spawn that failed mid-provision, a week whose
 * inventory settled early. Failures are isolated per mode: Classic's
 * empty shelf must not keep Triple Option's stocked.
 *
 * "Open" means what the lobby's inventory means: this week's room, slate
 * published, seats free.
 */
class OpenLobbiesCommand extends Command
{
    protected $signature = 'pickem:open-lobbies';

    protected $description = 'Keep at least one open public room per available mode for the current week';

    public function handle(CfbCalendar $calendar, SpawnPublicContest $spawn): int
    {
        $weekId = $calendar->defaultWeekId($calendar->currentYear());

        if ($weekId === null || ($week = Week::find($weekId)) === null) {
            $this->info('No current week to stock.');

            return self::SUCCESS;
        }

        $spawned = 0;

        foreach (ContestMode::cases() as $mode) {
            if (! $mode->available()) {
                continue;
            }

            try {
                if ($this->hasOpenRoom($mode, $week)) {
                    continue;
                }

                if ($spawn->handle($mode, $week) !== null) {
                    $spawned++;
                }
            } catch (Throwable $e) {
                Log::warning('pickem:open-lobbies failed for a mode', [
                    'mode' => $mode->value,
                    'week_id' => $week->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Spawned {$spawned} room(s).");

        return self::SUCCESS;
    }

    private function hasOpenRoom(ContestMode $mode, Week $week): bool
    {
        return Group::query()
            ->where('kind', Group::KIND_LOBBY)
            ->where('week_id', $week->id)
            ->whereNull('filled_at')
            ->whereHas('contests', fn ($q) => $q
                ->where('mode', $mode)
                ->whereHas('slates', fn ($s) => $s
                    ->where('week_id', $week->id)
                    ->where('status', Slate::PUBLISHED)))
            ->withCount('memberships')
            ->get()
            ->contains(fn (Group $room) => $room->member_cap === null
                || $room->memberships_count < $room->member_cap);
    }
}
