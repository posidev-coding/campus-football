<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Services\Contests\PickGrader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One game moved: regrade its picks, and flip any slate it finished.
 *
 * Dispatched by the GameScoreChanged / GameWentFinal listeners — the
 * event-driven half of "live scoring from the second a game kicks". The
 * job reads OUR database only; the events already paid the ESPN cost.
 * Unique per game, so a scoring flurry collapses to one run at a time,
 * and everything here treats the event as an idempotent "this game moved"
 * signal — the row is reloaded and never trusted from the event.
 *
 * When the last game of a published slate finals, the slate flips to
 * PRELIM — every game final, nothing paid — and waits for the
 * official-final sweep. The status UPDATE is the claim, so a double fire
 * flips once.
 */
class GradeGamePicks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Must stay below the queue's `retry_after` (90s) — the ESPN sibling invariant. */
    public int $timeout = 60;

    public int $tries = 3;

    /**
     * Without this, the unique lock has NO expiry: a worker killed mid-job
     * strands this game's grading for the rest of the season, because the
     * SETNX key lives on Redis DB 0 where cache:clear cannot reach it.
     * Deliberately above $timeout, so a timed-out run has already died
     * before its lock lapses and the retry can take it.
     */
    public int $uniqueFor = 120;

    public function __construct(public int $gameId)
    {
        $this->onQueue('live');
    }

    public function uniqueId(): string
    {
        return (string) $this->gameId;
    }

    public function handle(PickGrader $grader): void
    {
        $game = Game::query()->find($this->gameId);

        if ($game === null || ! $game->hasKickedOff()) {
            return;
        }

        $slateGames = SlateGame::query()
            ->where('game_id', $game->id)
            ->whereHas('slate', fn ($q) => $q->whereIn('status', [Slate::PUBLISHED, Slate::PRELIM]))
            ->with(['slate.contest', 'picks'])
            ->get();

        foreach ($slateGames as $slateGame) {
            $grader->gradeSlateGame($slateGame, $game);
        }

        if (! $game->completed) {
            return;
        }

        foreach ($slateGames->pluck('slate_id')->unique() as $slateId) {
            $this->flipIfPreliminaryFinal((int) $slateId);
        }
    }

    /** All games final → published becomes prelim, exactly once. */
    private function flipIfPreliminaryFinal(int $slateId): void
    {
        $unfinished = SlateGame::query()
            ->where('slate_id', $slateId)
            ->whereHas('game', fn ($q) => $q->where('completed', false))
            ->exists();

        if (! $unfinished) {
            Slate::query()
                ->whereKey($slateId)
                ->where('status', Slate::PUBLISHED)
                ->update(['status' => Slate::PRELIM]);
        }
    }
}
