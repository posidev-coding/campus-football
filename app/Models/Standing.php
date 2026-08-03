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
