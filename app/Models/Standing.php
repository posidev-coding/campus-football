<?php

namespace App\Models;

use App\Enums\StandingSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'season_year', 'conference_id', 'team_id', 'source',
    'overall_wins', 'overall_losses', 'overall_ties',
    'conf_wins', 'conf_losses', 'conf_ties',
    'home_record', 'away_record', 'vs_ranked_record', 'streak',
    'win_pct', 'conf_win_pct', 'points_for', 'points_against',
    'point_differential', 'playoff_seed', 'games_behind',
    'diverged_at', 'divergence', 'synced_at',
])]
class Standing extends Model
{
    protected function casts(): array
    {
        return [
            'source' => StandingSource::class,
            'win_pct' => 'float',
            'conf_win_pct' => 'float',
            'divergence' => 'array',
            'diverged_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    public function scopeFromEspn(Builder $query): Builder
    {
        return $query->where('source', StandingSource::Espn);
    }

    public function scopeComputed(Builder $query): Builder
    {
        return $query->where('source', StandingSource::Computed);
    }

    /**
     * Only the row filed under the team's OWN conference for that season.
     *
     * ESPN publishes a team's standings under every group it belongs to, not
     * just its conference, so `standings.conference_id` is not "this team's
     * conference" — it is "the group we asked for". Measured across 2024-2026,
     * every FBS and FCS team carries at least two rows: one under its
     * conference and one under the division group (80 "FBS", 81 "FCS", both
     * `is_conference: 0`, 138 and 128 rows for 2026). A Sun Belt or SWAC team
     * carries a third, under its East/West half.
     *
     * `team_seasons` is the one place that says which of those is the team's
     * actual conference — the same season-scoped membership every label on
     * every screen already reads — so matching against it selects the right
     * row and drops the duplicates. Being strict costs nothing: every team
     * holding an ESPN standings row holds one under its own conference
     * (263/263, 265/265 and 266/266 for 2024, 2025 and 2026).
     *
     * `is_conference` is deliberately NOT consulted. It would be inert — the
     * only team_seasons rows pointing at a non-conference group are DII/DIII
     * and NAIA teams, which SyncStandings never fetches — and trusting an ESPN
     * flag to decide whether a real conference counts is the more fragile of
     * the two tests.
     */
    public function scopeInOwnConference(Builder $query, int $year): Builder
    {
        return $query->whereExists(
            fn (\Illuminate\Database\Query\Builder $sub) => $sub
                ->from('team_seasons')
                ->whereColumn('team_seasons.team_id', 'standings.team_id')
                ->whereColumn('team_seasons.conference_id', 'standings.conference_id')
                ->where('team_seasons.season_year', $year)
        );
    }

    public function scopeDiverged(Builder $query): Builder
    {
        return $query->whereNotNull('diverged_at');
    }

    /**
     * Conference standings order: conference record first, then overall, then
     * point differential as the tiebreak. Real conference tiebreakers are more
     * involved (head-to-head, division, records vs common opponents), but those
     * only matter at the top of a race and ESPN publishes `playoff_seed` for
     * exactly that — so prefer the seed when present.
     */
    public function scopeInStandingsOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('playoff_seed IS NULL, playoff_seed')
            ->orderByDesc('conf_win_pct')
            ->orderByDesc('conf_wins')
            ->orderByDesc('win_pct')
            ->orderByDesc('point_differential');
    }

    /**
     * Games in the books. Zero for every team in a season nobody has played
     * yet, which is what separates a standing from an alphabetical accident.
     */
    public function gamesPlayed(): int
    {
        return (int) $this->overall_wins + (int) $this->overall_losses + (int) $this->overall_ties;
    }

    public function conferenceRecord(): string
    {
        return $this->formatRecord($this->conf_wins, $this->conf_losses, $this->conf_ties);
    }

    public function overallRecord(): string
    {
        return $this->formatRecord($this->overall_wins, $this->overall_losses, $this->overall_ties);
    }

    private function formatRecord(int $wins, int $losses, int $ties): string
    {
        return $ties > 0 ? "{$wins}-{$losses}-{$ties}" : "{$wins}-{$losses}";
    }
}
