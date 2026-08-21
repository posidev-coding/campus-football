<?php

namespace App\Actions;

use App\Enums\TiebreakerMetric;
use App\Models\Contest;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\Week;
use App\Services\Contests\SuggestSlate;
use App\Support\Cadence;
use Carbon\CarbonInterface;

/**
 * The commissioner overslept: publish the STANDARD slate.
 *
 * Commissioners are humans — they forget, or Saturday's buzz starts on
 * Tuesday — and a group must never open the week to a blank slate. Past
 * the deadline this fills the slate from the same suggestion engine the
 * builder pre-fills from (best quality games, half-pointed lines, tiers
 * banded), designates the top game's combined points as the tiebreaker —
 * the one metric that is always automatable — and publishes through the
 * exact validation a human publish passes.
 *
 * An unpublished partial draft is REPLACED, not completed: half a slate
 * plus half a suggestion is nobody's slate, and the commissioner had until
 * the deadline to finish theirs. A published slate is never touched.
 *
 * Returns the published slate, or null when the week could not support a
 * valid slate (a thin week failing validation stays a loud draft rather
 * than publishing garbage).
 */
class AutoPublishStandardSlate
{
    public function __construct(
        private SuggestSlate $suggest,
        private PublishSlate $publish,
    ) {}

    /**
     * @param  CarbonInterface|null  $saturday  which Saturday of the week to
     *                                          build; defaults to its primary card
     */
    public function handle(Contest $contest, Week $week, ?CarbonInterface $saturday = null): ?Slate
    {
        /*
         * Keyed on the SATURDAY, not the week — an ESPN week can hold two,
         * and a contest gets one slate per Saturday played. A week with no
         * Saturday at all (nothing synced, no range) has nothing to build.
         */
        $saturday ??= Cadence::saturdayOf($week);

        if ($saturday === null) {
            return null;
        }

        $slate = Slate::query()->firstOrCreate(
            ['contest_id' => $contest->id, 'saturday' => $saturday->toDateString()],
            ['week_id' => $week->id, 'status' => Slate::DRAFT],
        );

        if ($slate->status !== Slate::DRAFT) {
            return null;
        }

        $slate->games()->delete();
        $slate->update([
            'tiebreaker_slate_game_id' => null,
            'tiebreaker_metric' => null,
            'tiebreaker_team_id' => null,
        ]);

        $suggested = $this->suggest->for($contest, $week, $saturday);

        foreach ($suggested as $i => $row) {
            $seed = array_diff_key($row, array_flip(['game_id', 'score']));

            SlateGame::query()->create([
                'slate_id' => $slate->id,
                'game_id' => $row['game_id'],
                'position' => $i + 1,
                ...$seed,
            ]);
        }

        $top = $slate->games()->orderBy('position')->first();

        if ($top !== null) {
            $slate->update([
                'tiebreaker_slate_game_id' => $top->id,
                'tiebreaker_metric' => TiebreakerMetric::CombinedPoints,
            ]);
        }

        $problems = $this->publish->force($slate->fresh());

        return $problems === [] ? $slate->fresh() : null;
    }
}
