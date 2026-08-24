<?php

namespace App\Actions;

use App\Exceptions\PickemParticipationGated;
use App\Models\Slate;
use App\Models\User;
use App\Services\Contests\BearPicks;
use App\Services\Contests\GameQualityScore;
use App\Support\SlateAuthority;
use Illuminate\Support\Facades\DB;

/**
 * Publish a slate: validate the commissioner's lines against the mode's
 * rules and flip the status — atomically.
 *
 * The contest lines are already ON the rows by the time this runs: seeded
 * from the book when each game joined the slate, half-pointed by the
 * league's no-push law, and adjusted by the commissioner through
 * SetSlateGameLine. Publishing COMMITS them — nothing here reads the
 * market, so the number every pick grades against is exactly the number
 * the commissioner signed off on, whatever the book does after.
 *
 * Returns the violation keys, [] meaning "published". Re-publishing a
 * published slate is a quiet no-op: the button pressed twice must not
 * scold, and a committed slate never changes — which is also what keeps the
 * quality snapshot below a record of the moment of publish rather than of
 * the last time somebody pressed the button.
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

            // The Bear is drawn at publish — once. A slate cloned from a
            // sibling room arrives with bear_theme already set and keeps
            // that Bear verbatim (the identical-house-slate rule).
            if ($engine->hasBear() && $slate->bear_theme === null) {
                $this->bear->seed($slate);
            }

            $this->snapshotQuality($slate);

            $slate->update(['status' => Slate::PUBLISHED, 'published_at' => now()]);

            return [];
        });
    }

    /**
     * Freeze the quality score and its inputs onto every row.
     *
     * The only reason this happens at publish rather than in a later batch:
     * three of the five components ride ESPN feeds that are current-window
     * only, so a slate published without this is gone as calibration data
     * permanently. Nothing reads these columns — they are the labeled rows a
     * future re-fit of the weights will need, and there is no other way to get
     * them. See the migration for the measurement behind that.
     *
     * NOT SuggestSlate::AFFINITY_BONUS. The base score is a per-game fact and
     * affinity is a per-group opinion; folding the opinion in would make the
     * same game score differently on two rooms' slates and poison the feature.
     *
     * Null is recorded honestly and is a legitimate outcome here, not a
     * failure: components() reads the LIVE current-phase odd, while this
     * slate's line was frozen into `spread` when the game was added — possibly
     * days earlier, and by a book that has since moved on. Tightness and
     * movement stay recomputable from the frozen columns either way.
     */
    private function snapshotQuality(Slate $slate): void
    {
        /*
         * The eager load is load-bearing, and no feature test can prove it:
         * Model::preventLazyLoading()'s per-instance flag is false under test,
         * so a missing one resolves silently here and N+1s only in production
         * — inside a transaction, once per published slate. Fifteen games
         * would be ~45 extra queries holding a write lock.
         */
        $slate->loadMissing(['games.game.odds', 'games.game.predictor']);

        foreach ($slate->games as $slateGame) {
            $components = GameQualityScore::components($slateGame->game);

            $slateGame->update([
                'quality' => $components === null ? null : GameQualityScore::total($components),
                'quality_parts' => $components,
            ]);
        }
    }
}
