<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A live game's score or status moved on a sync pass.
 *
 * The pick'em subscription point: contest scoring needs exactly what the live
 * tier already writes — scores and status — so a future contest-recompute
 * listener subscribes here instead of polling or touching sync code. No
 * listeners exist yet.
 *
 * Scalars only, never the model: a queued listener would serialize whatever
 * rides the event, and scores-at-event-time are the honest payload anyway.
 * Fired once per sync pass that changed something, so a listener must treat
 * it as an idempotent "this game moved" signal, not a delta.
 */
class GameScoreChanged
{
    use Dispatchable;

    public function __construct(
        public int $gameId,
        public ?int $homeScore,
        public ?int $awayScore,
        public string $status,
    ) {}
}
