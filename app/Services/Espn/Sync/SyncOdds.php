<?php

namespace App\Services\Espn\Sync;

use App\Models\GameOdd;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Betting lines, taken from the scoreboard payload we already fetch.
 *
 * The core API exposes a per-game odds resource with ESPN's own open/current/
 * close blocks, but reading it costs one request per game — ~950 a season. The
 * scoreboard carries the current line inline for upcoming games at no extra
 * cost (63 of 260 upcoming 2026 games had one), so odds ride along with the
 * existing game sync for free.
 *
 * The trade is that ESPN's "opening line" is not available this way. We build
 * our own instead: the first line we ever observe for a game is frozen as
 * `open` and never rewritten, `current` is overwritten on every sync, and the
 * last line seen before kickoff becomes `close`. The delta between open and
 * current is the line movement that feeds the Game Quality Score — the closest
 * public proxy for where betting money is going, since no public API publishes
 * handle or volume.
 *
 * A consequence worth stating plainly: line movement cannot be backfilled.
 * ESPN returns odds:null for completed games, so historical seasons will have
 * game quality without this signal, and our own history only starts
 * accumulating from the first sync forward.
 */
class SyncOdds
{
    /**
     * Store the lines carried on one competition payload.
     *
     * `$existing` is the caller's preloaded row map for a whole scoreboard
     * payload, keyed `game:provider:phase` — the live tier passes it so a
     * minute's sync reads odds once instead of three times per provider
     * block. A caller without one gets this game's own rows loaded here,
     * one query, same semantics.
     *
     * @param  Collection<string, GameOdd>|null  $existing
     * @return int number of provider blocks written or updated
     */
    public function fromCompetition(int $gameId, array $competition, bool $gameStarted = false, ?Collection $existing = null): int
    {
        $existing ??= GameOdd::query()
            ->where('game_id', $gameId)
            ->get()
            ->keyBy(fn (GameOdd $odd) => "{$odd->game_id}:{$odd->provider_id}:{$odd->phase}");

        $written = 0;

        foreach ($competition['odds'] ?? [] as $odds) {
            $providerId = isset($odds['provider']['id']) ? (int) $odds['provider']['id'] : null;

            $values = [
                'provider' => $odds['provider']['name'] ?? null,
                'spread' => isset($odds['spread']) ? (float) $odds['spread'] : null,
                'over_under' => isset($odds['overUnder']) ? (float) $odds['overUnder'] : null,
                'moneyline_home' => $this->moneyline($odds, 'homeTeamOdds'),
                'moneyline_away' => $this->moneyline($odds, 'awayTeamOdds'),
                'favorite_team_id' => $this->favoriteTeamId($odds),
                'details' => $odds['details'] ?? null,
                'captured_at' => CarbonImmutable::now(),
            ];

            // Nothing usable in this provider's block.
            if ($values['spread'] === null && $values['over_under'] === null) {
                continue;
            }

            // The first line we ever see is the open, and it is never rewritten.
            $this->put($existing, $gameId, $providerId, GameOdd::OPEN, $values, rewrite: false);

            $this->put($existing, $gameId, $providerId, GameOdd::CURRENT, $values, rewrite: true);

            // Once the game is under way the line stops moving; freeze it.
            if ($gameStarted) {
                $this->put($existing, $gameId, $providerId, GameOdd::CLOSE, $values, rewrite: true);
            }

            $written++;
        }

        return $written;
    }

    /**
     * One phase row against the preloaded map — firstOrCreate when
     * `$rewrite` is false, updateOrCreate when true, without the per-row
     * SELECT either used to pay. New rows join the map so the rest of the
     * payload sees them.
     *
     * @param  Collection<string, GameOdd>  $existing
     * @param  array<string, mixed>  $values
     */
    private function put(Collection $existing, int $gameId, ?int $providerId, string $phase, array $values, bool $rewrite): void
    {
        $key = "{$gameId}:{$providerId}:{$phase}";
        $row = $existing->get($key);

        if ($row === null) {
            $existing->put($key, GameOdd::create([
                'game_id' => $gameId,
                'provider_id' => $providerId,
                'phase' => $phase,
                ...$values,
            ]));

            return;
        }

        if (! $rewrite) {
            return;
        }

        $row->fill($values);

        if ($row->isDirty()) {
            $row->save();
        }
    }

    private function moneyline(array $odds, string $side): ?int
    {
        $value = $odds[$side]['moneyLine'] ?? $odds[$side]['moneyline'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * ESPN marks the favorite on whichever side block carries `favorite: true`.
     */
    private function favoriteTeamId(array $odds): ?int
    {
        foreach (['homeTeamOdds', 'awayTeamOdds'] as $side) {
            if (($odds[$side]['favorite'] ?? false) === true) {
                $id = $odds[$side]['team']['id'] ?? null;

                return $id === null ? null : (int) $id;
            }
        }

        return null;
    }
}
