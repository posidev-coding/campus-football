<?php

namespace App\Services\Espn\Sync;

use App\Models\Standing;

/**
 * Compares the ESPN feed against our own computation and flags disagreement.
 *
 * The point is early warning, not correction. Neither source is edited: the
 * ESPN row stays authoritative and keeps serving the UI, while `diverged_at`
 * and a per-field `divergence` blob make the disagreement visible in the admin
 * panel. That turns a silent data-quality regression — the failure mode that
 * made standings unreliable for three versions — into something that shows up
 * as an alert while records are still right on screen.
 *
 * Some divergence is expected and benign: FCS opponents, games we have not
 * ingested yet, and vacated wins all move one source without the other. The
 * tolerance below exists so those do not drown the real signal.
 */
class ReconcileStandings
{
    /** Games' worth of difference tolerated before a row is flagged. */
    private const TOLERANCE = 1;

    public function handle(int $year): int
    {
        $computed = Standing::query()
            ->computed()
            ->where('season_year', $year)
            ->get()
            ->keyBy(fn (Standing $s) => $s->team_id.':'.$s->conference_id);

        $flagged = 0;

        Standing::query()
            ->fromEspn()
            ->where('season_year', $year)
            ->chunkById(500, function ($rows) use ($computed, &$flagged) {
                foreach ($rows as $espn) {
                    $mirror = $computed->get($espn->team_id.':'.$espn->conference_id);

                    $divergence = $mirror ? $this->compare($espn, $mirror) : null;

                    // Only touch the row when the flag state actually changes,
                    // so `diverged_at` reflects when a problem started rather
                    // than when the reconciler last ran.
                    if ($divergence === null) {
                        if ($espn->diverged_at !== null) {
                            $espn->forceFill(['diverged_at' => null, 'divergence' => null])->save();
                        }

                        continue;
                    }

                    $espn->forceFill([
                        'diverged_at' => $espn->diverged_at ?? now(),
                        'divergence' => $divergence,
                    ])->save();

                    $flagged++;
                }
            });

        return $flagged;
    }

    /**
     * @return array<string, array{espn:int, computed:int}>|null
     */
    private function compare(Standing $espn, Standing $computed): ?array
    {
        $fields = ['overall_wins', 'overall_losses', 'conf_wins', 'conf_losses'];

        $divergence = [];

        foreach ($fields as $field) {
            $delta = abs($espn->{$field} - $computed->{$field});

            if ($delta > self::TOLERANCE) {
                $divergence[$field] = [
                    'espn' => $espn->{$field},
                    'computed' => $computed->{$field},
                ];
            }
        }

        return $divergence === [] ? null : $divergence;
    }

    public static function divergedCount(int $year): int
    {
        return Standing::diverged()->where('season_year', $year)->count();
    }
}
