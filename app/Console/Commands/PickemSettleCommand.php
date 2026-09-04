<?php

namespace App\Console\Commands;

use App\Actions\SettleSlate;
use App\Console\Concerns\TracksFeedRun;
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
 * completed game still on an unsettled slate — a game imported
 * already-final never fires GameWentFinal, and one missed sync minute must
 * not strand a slate forever. SETTLE walks every unsettled slate whose
 * week has passed Cadence::officialFinal and pays it out; the action
 * re-grades first, so a score or stat corrected during the stat-settling
 * window is absorbed before anyone is paid.
 */
class PickemSettleCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'pickem:settle';

    protected $description = 'Regrade stranded games and settle slates whose week has turned official';

    public function handle(SettleSlate $settle): int
    {
        /*
         * EVERY TICK IS RECORDED, and most ticks have nothing to do. The
         * sweep runs hourly all season while a slate is only settleable in
         * the hours after its week turns official, so an empty pass is the
         * normal pass — and it is exactly the state that used to render
         * identically to a dead worker. Zero is a measured fact here, not a
         * substituted default, so the count returns from BELOW the loops
         * rather than from an early bail.
         *
         * DB-only: no ESPN request is spent, so the trait's counter reads 0
         * on every row. That is correct and it stays 0.
         */
        $this->trackRun('settle-slates', null, function () use ($settle): int {
            $open = Slate::query()
                ->whereIn('status', [Slate::PUBLISHED, Slate::PRELIM])
                ->with(['games.game', 'week'])
                ->get();

            // Unique game ids, not slate games: on a real Saturday the same game
            // sits on a dozen slates, and the job grades every slate it touches.
            $finalGameIds = $open
                ->flatMap(fn ($slate) => $slate->games)
                ->filter(fn ($slateGame) => $slateGame->game->completed)
                ->pluck('game_id')
                ->unique()
                ->values();

            foreach ($finalGameIds as $gameId) {
                GradeGamePicks::dispatch($gameId);
            }

            $rescued = $finalGameIds->count();

            $settled = 0;

            foreach ($open as $slate) {
                // The slate's OWN Saturday, not its week's — a split ESPN week
                // holds two, and settling the second against the first's clock
                // would call a week official a fortnight early.
                $official = Cadence::officialFinal($slate->saturday);

                if ($official === null || now()->lessThan($official)) {
                    continue;
                }

                // One bad slate must not cost the rest of the league.
                try {
                    if ($settle->handle($slate)) {
                        $settled++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Settling slate {$slate->id} failed: {$e->getMessage()}");
                }
            }

            $this->info("Redispatched grading for {$rescued} final game(s); settled {$settled} slate(s).");

            /*
             * BOTH PASSES COUNT. The console line keeps them apart because a
             * reader wants to know which one moved, but the ledger holds one
             * number and a rescue dispatch is work this tick did — a pass that
             * redispatched grading for a stranded Saturday and settled nothing
             * is not a quiet pass, and recording it as zero would say it was.
             */
            return $rescued + $settled;
        });

        return self::SUCCESS;
    }
}
