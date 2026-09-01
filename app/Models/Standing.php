<?php

namespace App\Models;

use App\Enums\StandingSource;
use Database\Factories\StandingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    /** @use HasFactory<StandingFactory> */
    use HasFactory;

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
     * Conference standings order.
     *
     * ESPN's `playoff_seed` is the conference's own standings order, and it
     * carries the tiebreakers our columns cannot reconstruct — head-to-head,
     * division, records vs common opponents. Sorted against five completed
     * seasons, ordering by record alone moves a third of all rows off the
     * position ESPN gives them, so the seed leads whenever it can be trusted.
     *
     * It cannot be trusted until every team in the conference has one. ESPN
     * seeds only the teams that have PLAYED and publishes `playoffSeed: 0` for
     * the rest — 0 is "unseeded", not first — and its own site puts those
     * teams between the winners and the losers rather than above everyone.
     * Measured live on 2026-09-01, mid-week-1 ACC: Virginia, Florida State,
     * North Carolina and Stanford 1-0 with seeds 1-4, twelve teams 0-0 with
     * seed 0, and NC State 0-1 with seed 5, rendered in exactly that order.
     * Sorting on the raw seed put all twelve teams that had not kicked off
     * above the four that had won.
     *
     * So the seed applies only where the whole conference carries one; while
     * any team is unseeded it goes inert for that conference (every row NULL,
     * a uniform tie) and the records decide. A team with no games is counted
     * as .500 there — ahead of a team that has lost, behind a team that has
     * won, which is the placement ESPN itself uses and the one thing this
     * order must never get wrong.
     *
     * The percentages are derived from the win/loss columns rather than read
     * from `win_pct` / `conf_win_pct`, because those carry the same sentinel
     * one column over: ESPN writes 0.0000 for a team with no games, which is
     * indistinguishable from a team that has lost them all.
     *
     * The gate is a correlated subquery, which costs a lookup per row: 45ms
     * over the league-wide 265 rows `TeamGlance` reads, 18ms for one division
     * on the standings screen. Both sit behind a 900s cache. A join would be
     * cheaper and would break every caller that selects `team_id` unqualified.
     */
    public function scopeInStandingsOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN EXISTS ('
                .'SELECT 1 FROM standings unseeded'
                .' WHERE unseeded.season_year = standings.season_year'
                .' AND unseeded.conference_id <=> standings.conference_id'
                .' AND unseeded.source = standings.source'
                .' AND COALESCE(unseeded.playoff_seed, 0) = 0'
                .') THEN NULL ELSE standings.playoff_seed END')
            ->orderByRaw(self::winPercentage('conf').' DESC')
            ->orderByDesc('conf_wins')
            ->orderByRaw(self::winPercentage('overall').' DESC')
            ->orderByDesc('point_differential');
    }

    /**
     * Win percentage from the record columns, with no games played as .500.
     *
     * @param  'conf'|'overall'  $prefix
     */
    private static function winPercentage(string $prefix): string
    {
        $played = "{$prefix}_wins + {$prefix}_losses + {$prefix}_ties";

        return "CASE WHEN ({$played}) = 0 THEN 0.5"
            ." ELSE ({$prefix}_wins + {$prefix}_ties / 2) / ({$played}) END";
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
