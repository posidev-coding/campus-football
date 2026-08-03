<?php

namespace App\Services\Nil;

use App\Models\Team;
use Illuminate\Support\Collection;

/**
 * A source of NIL-related news for a team.
 *
 * ESPN has no NIL endpoint — none exists, and nothing in the public API
 * approximates a valuation. This interface exists so that fact is a swappable
 * implementation detail rather than something baked into a page: the default
 * filters the team news feed we already fetch, and a paid provider (On3,
 * Opendorse) could later be dropped in behind the same contract.
 */
interface NilNewsProvider
{
    /**
     * @return Collection<int, array{headline:string, description:?string, url:?string, published:?string}>
     */
    public function forTeam(Team $team, int $limit = 10): Collection;
}
