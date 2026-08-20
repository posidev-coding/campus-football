<?php

namespace App\Enums;

use App\Models\Conference;
use App\Models\Game;
use App\Models\Season;
use App\Models\TeamSeason;
use App\Models\Week;
use App\Support\GameRanks;
use Illuminate\Support\Collection;

/**
 * A themed slate's admission rule — which of a Saturday's games belong on a
 * flavored board. Carried as `slate_filter` in `contests.settings`, applied
 * by SuggestSlate AFTER its slate-window and Saturday checks, so a filter
 * only ever NARROWS the standard candidate pool; games without a usable
 * line still drop afterward through the quality score.
 *
 * Time-of-day arms run per game in PHP, never in SQL — the ET boundary
 * shifts under DST (the same law as Game::inSlateWindow()). A filter that
 * empties the pool is a room that never spawns, not a short board.
 */
enum SlateFilter: string
{
    case Ranked = 'ranked';
    case Primetime = 'primetime';
    case Conference = 'conference';

    /** The ET hour a kickoff becomes a night game. */
    private const PRIMETIME_HOUR = 19;

    /**
     * @param  Collection<int, Game>  $games
     * @param  array<string, mixed>  $settings  the contest's whole settings row — Conference reads its companion key
     * @return Collection<int, Game>
     */
    public function apply(Collection $games, array $settings, Week $week): Collection
    {
        return match ($this) {
            self::Ranked => $games->filter(function (Game $game): bool {
                $ranks = GameRanks::forGame($game);

                return $ranks['home'] !== null || $ranks['away'] !== null;
            }),

            self::Primetime => $games->filter(fn (Game $game): bool => $game->kickoff_at !== null
                && $game->kickoff_at->timezone(config('cfb.timezone'))->hour >= self::PRIMETIME_HOUR),

            self::Conference => $this->conferenceGames($games, $settings, $week),
        };
    }

    /**
     * Every game with a member on EITHER side of the ball. Membership is
     * season-scoped through team_seasons, never a scalar off teams — and
     * either-side is the September reality: a conference card is mostly
     * its members' non-conference dates until league play starts.
     *
     * @param  Collection<int, Game>  $games
     * @return Collection<int, Game>
     */
    private function conferenceGames(Collection $games, array $settings, Week $week): Collection
    {
        $conferenceId = Conference::query()
            ->where('abbreviation', $settings['filter_conference'] ?? null)
            ->value('id');

        // No conference named, or an abbreviation we do not hold, admits
        // NOTHING — an empty pool is loud (no room spawns), a fallback to
        // "all games" would quietly sell the wrong card.
        if ($conferenceId === null) {
            return $games->take(0);
        }

        $memberIds = TeamSeason::query()
            ->where('season_year', Season::query()->whereKey($week->season_id)->value('year'))
            ->where('conference_id', $conferenceId)
            ->pluck('team_id')
            ->flip();

        return $games->filter(fn (Game $game): bool => $memberIds->has($game->home_team_id)
            || $memberIds->has($game->away_team_id));
    }
}
