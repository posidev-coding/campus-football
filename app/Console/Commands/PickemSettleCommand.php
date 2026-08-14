<?php

namespace App\Console\Commands;

use App\Actions\SettleSlate;
use App\Jobs\GradeGamePicks;
use App\Models\Slate;
use App\Support\Cadence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The settle sweep: the safety net under the event-driven grading, and the
 * hand that turns a week OFFICIAL.
 *
 * Two passes, both DB-only. RESCUE re-dispatches grading for every
 * completed game still on an unsettled board — a game imported
 * already-final never fires GameWentFinal, and one missed sync minute must
 * not strand a slate forever. SETTLE walks every unsettled board whose
 * week has passed Cadence::officialFinal and pays it out; the action
 * re-grades first, so a score or stat corrected during the stat-settling
 * window is absorbed before anyone is paid.
 */
class PickemSettleCommand extends Command
{
    protected $signature = 'pickem:settle';

    protected $description = 'Regrade stranded games and settle boards whose week has turned official';

    public function handle(SettleSlate $settle): int
    {
        $open = Slate::query()
            ->whereIn('status', [Slate::PUBLISHED, Slate::PRELIM])
            ->with(['games.game', 'week'])
            ->get();

        $rescued = 0;

        foreach ($open as $slate) {
            foreach ($slate->games as $slateGame) {
                if ($slateGame->game->completed) {
                    GradeGamePicks::dispatch($slateGame->game_id);
                    $rescued++;
                }
            }
        }

        $settled = 0;

        foreach ($open as $slate) {
            $official = Cadence::officialFinal($slate->week);

            if ($official === null || now()->lessThan($official)) {
                continue;
            }

            // One bad board must not cost the rest of the league.
            try {
                if ($settle->handle($slate)) {
                    $settled++;
                }
            } catch (\Throwable $e) {
                Log::warning("Settling slate {$slate->id} failed: {$e->getMessage()}");
            }
        }

        $this->info("Redispatched grading for {$rescued} final game(s); settled {$settled} board(s).");

        return self::SUCCESS;
    }
}
