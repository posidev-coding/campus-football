<?php

namespace App\Actions;

use App\Exceptions\PickemParticipationGated;
use App\Models\Slate;
use App\Models\User;
use App\Services\Contests\BearPicks;
use App\Support\SlateAuthority;
use Illuminate\Support\Facades\DB;

/**
 * Publish a board: validate the commissioner's lines against the mode's
 * rules and flip the status — atomically.
 *
 * The contest lines are already ON the rows by the time this runs: seeded
 * from the book when each game joined the board, half-pointed by the
 * league's no-push law, and adjusted by the commissioner through
 * SetSlateGameLine. Publishing COMMITS them — nothing here reads the
 * market, so the number every pick grades against is exactly the number
 * the commissioner signed off on, whatever the book does after.
 *
 * Returns the violation keys, [] meaning "published". Re-publishing a
 * published board is a quiet no-op: the button pressed twice must not
 * scold, and a committed board never changes.
 */
class PublishSlate
{
    public function __construct(private BearPicks $bear) {}

    /**
     * @return list<string> Voice keys for each violation; [] = published
     *
     * @throws PickemParticipationGated when the commissioner is unverified
     */
    public function handle(User $actor, Slate $slate): array
    {
        SlateAuthority::commissioner($actor, $slate);

        if (! $actor->hasVerifiedEmail()) {
            throw new PickemParticipationGated;
        }

        return $this->force($slate);
    }

    /**
     * The SYSTEM's door: the same validation and commit with no actor
     * gates — the deadline fallback and house lobbies publish through
     * here, and nothing user-reachable may call it directly.
     */
    public function force(Slate $slate): array
    {
        if ($slate->status !== Slate::DRAFT) {
            return [];
        }

        return DB::transaction(function () use ($slate) {
            $slate->loadMissing('contest');

            $engine = $slate->contest->mode->engine($slate->contest->settings);
            $problems = $engine->validateForPublish($slate);

            if ($problems !== []) {
                return $problems;
            }

            // The Bear boards at publish — once. A slate cloned from a
            // sibling room arrives with bear_theme already set and keeps
            // that Bear verbatim (the identical-house-slate rule).
            if ($engine->hasBear() && $slate->bear_theme === null) {
                $this->bear->seed($slate);
            }

            $slate->update(['status' => Slate::PUBLISHED, 'published_at' => now()]);

            return [];
        });
    }
}
