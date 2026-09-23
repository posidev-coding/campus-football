<?php

namespace App\Services\Espn\Sync;

use App\Models\Game;
use App\Models\GameOdd;
use App\Models\Week;
use App\Services\Espn\EspnClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
     * How fresh a current line must be before the core fallback leaves a
     * game alone. The hourly tier rewrites `captured_at` on every line it
     * parses, so a row older than this means the scoreboard stopped
     * delivering one — not that the line stood still.
     */
    public const STALE_AFTER_HOURS = 3;

    /** One diagnostic per sync run — the evidence, not a log flood. */
    private bool $reportedUnusable = false;

    public function __construct(private EspnClient $espn) {}

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
        $blocks = is_array($competition['odds'] ?? null) ? $competition['odds'] : [];
        $sides = $this->sides($competition);

        foreach ($blocks as $odds) {
            // A `$ref` stub or a scalar is not a line — and indexing into
            // one threw, which the per-event guard swallowed silently.
            if (! is_array($odds)) {
                continue;
            }

            $providerId = is_numeric($odds['provider']['id'] ?? null) ? (int) $odds['provider']['id'] : null;
            $line = $this->line($odds, $sides);

            $values = [
                'provider' => $odds['provider']['name'] ?? null,
                'spread' => $line['spread'],
                'over_under' => $this->overUnder($odds),
                'moneyline_home' => $this->moneyline($odds, 'homeTeamOdds'),
                'moneyline_away' => $this->moneyline($odds, 'awayTeamOdds'),
                'favorite_team_id' => $this->favoriteTeamId($odds, $sides, $line['favored']),
                'details' => is_string($odds['details'] ?? null) ? $odds['details'] : null,
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

        /*
         * ESPN carried odds and none of them parsed. This is the failure that
         * ran silent for a week in September 2026 — every group's build door
         * shut on four lined games while ESPN's site showed a spread on all
         * of them. Say so once per run, with the shape that defeated us.
         */
        if ($written === 0 && $blocks !== [] && ! $this->reportedUnusable) {
            $this->reportedUnusable = true;

            Log::warning('ESPN odds carried no line we could parse', [
                'game' => $gameId,
                'keys' => is_array($blocks[0] ?? null) ? array_keys($blocks[0]) : gettype($blocks[0] ?? null),
                'sample' => mb_substr((string) json_encode($blocks[0] ?? null), 0, 2000),
            ]);
        }

        return $written;
    }

    /**
     * The per-game fallback: ESPN's core odds resource for every upcoming
     * slate-window game in the week whose current line is missing or stale.
     *
     * The scoreboard is the free path and stays the primary one; this costs
     * one request per game (two when ESPN answers with `$ref` stubs), so it
     * only ever runs for games the scoreboard left without a line. On a week
     * the scoreboard behaves, that is zero requests. Every call goes through
     * the client's shared throttle, so a bad week is slower, never bursty.
     *
     * @return int games that came back with a line
     */
    public function refreshStale(Week $week): int
    {
        $fresh = CarbonImmutable::now()->subHours(self::STALE_AFTER_HOURS);

        $games = Game::query()
            ->slateEligible()
            ->where('week_id', $week->id)
            ->upcoming()
            ->where('kickoff_at', '<=', CarbonImmutable::now()->addDays(8))
            // Fresh means a current line from the last few hours — a pick'em
            // (spread 0) names no favorite and is still a fresh line, not a
            // reason to ask the core API again every run.
            ->whereDoesntHave('odds', fn ($query) => $query
                ->where('phase', GameOdd::CURRENT)
                ->whereNotNull('spread')
                ->where(fn ($line) => $line->whereNotNull('favorite_team_id')->orWhere('spread', 0))
                ->where('captured_at', '>=', $fresh))
            ->with(['homeTeam:id,abbreviation', 'awayTeam:id,abbreviation'])
            ->get()
            ->filter(fn (Game $game) => $game->inSlateWindow());

        $lined = 0;

        foreach ($games as $game) {
            if ($this->fromCore($game) > 0) {
                $lined++;
            }
        }

        return $lined;
    }

    /** One game's lines from the core API, through the same parser. */
    public function fromCore(Game $game): int
    {
        $body = $this->espn->core("events/{$game->id}/competitions/{$game->id}/odds", ttl: 0);

        $blocks = [];

        foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $item) {
            // Collections sometimes answer with bare `$ref` stubs.
            if (is_array($item) && isset($item['$ref']) && ! isset($item['provider'])) {
                $item = $this->espn->ref($item['$ref'], ttl: 0);
            }

            if (is_array($item)) {
                $blocks[] = $item;
            }
        }

        return $this->fromCompetition($game->id, [
            'odds' => $blocks,
            'competitors' => [
                ['homeAway' => 'home', 'team' => ['id' => $game->home_team_id, 'abbreviation' => $game->homeTeam?->abbreviation]],
                ['homeAway' => 'away', 'team' => ['id' => $game->away_team_id, 'abbreviation' => $game->awayTeam?->abbreviation]],
            ],
        ], gameStarted: false);
    }

    /**
     * Both sides' team ids and abbreviations, off the competitors list —
     * what lets a line be read from `details` or a side block that carries
     * only a `$ref` for its team.
     *
     * @return array{home: array{id: int|null, abbr: string|null}, away: array{id: int|null, abbr: string|null}}
     */
    private function sides(array $competition): array
    {
        $sides = ['home' => ['id' => null, 'abbr' => null], 'away' => ['id' => null, 'abbr' => null]];

        foreach (is_array($competition['competitors'] ?? null) ? $competition['competitors'] : [] as $competitor) {
            $side = $competitor['homeAway'] ?? null;

            if (! in_array($side, ['home', 'away'], true)) {
                continue;
            }

            $id = $competitor['team']['id'] ?? null;

            $sides[$side] = [
                'id' => is_numeric($id) && (int) $id > 0 ? (int) $id : null,
                'abbr' => is_string($competitor['team']['abbreviation'] ?? null) ? $competitor['team']['abbreviation'] : null,
            ];
        }

        return $sides;
    }

    /**
     * The line, from wherever ESPN put it, and the side it names favored.
     *
     * HOME-RELATIVE, ESPN's own convention for the top-level `spread` —
     * verified on two production payloads 2026-09-23: home underdog
     * Tennessee carried `spread: 4.5` beside "TEX -4.5", home favorite
     * Georgia `spread: -14` beside "UGA -14". When the top-level number is
     * absent the line is rebuilt in the same convention, from the
     * `pointSpread` home market or the `details` string, so line movement
     * always compares like with like.
     *
     * @return array{spread: float|null, favored: 'home'|'away'|null}
     */
    private function line(array $odds, array $sides): array
    {
        $spread = is_numeric($odds['spread'] ?? null) ? (float) $odds['spread'] : null;

        foreach (['current', 'close', 'open'] as $phase) {
            $spread ??= $this->number($odds['pointSpread']['home'][$phase]['line'] ?? null);
        }

        $details = is_string($odds['details'] ?? null) ? trim($odds['details']) : null;

        if ($spread === null && $details !== null && preg_match('/^(EVEN|PK|PICK)$/i', $details)) {
            $spread = 0.0;
        }

        // "UGA -10": the named team gives that number, so home-relative it
        // is -10 when UGA is home and +10 when UGA is away.
        if ($spread === null && $details !== null && preg_match('/^(\S+)\s+([+-]?\d+(?:\.\d+)?)$/', $details, $m)) {
            $spread = match (strtoupper($m[1])) {
                strtoupper((string) $sides['home']['abbr']) => (float) $m[2],
                strtoupper((string) $sides['away']['abbr']) => -(float) $m[2],
                default => null,
            };
        }

        return [
            'spread' => $spread,
            'favored' => match (true) {
                $spread === null, $spread == 0 => null,
                $spread < 0 => 'home',
                default => 'away',
            },
        ];
    }

    private function overUnder(array $odds): ?float
    {
        if (is_numeric($odds['overUnder'] ?? null)) {
            return (float) $odds['overUnder'];
        }

        foreach (['current', 'close', 'open'] as $phase) {
            $line = $this->number($odds['total']['over'][$phase]['line'] ?? null);

            if ($line !== null) {
                return abs($line);
            }
        }

        return null;
    }

    /** "-10.5", "+3", "o52.5", "u52.5" → a float; anything else → null. */
    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/^\s*[ou]?\s*([+-]?\d+(?:\.\d+)?)\s*$/i', $value, $m)) {
            return (float) $m[1];
        }

        return null;
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

        if (is_numeric($value)) {
            return (int) $value;
        }

        // The market block: moneyline.home.current.odds as "-280" / "+230".
        $market = $side === 'homeTeamOdds' ? 'home' : 'away';

        foreach (['current', 'close', 'open'] as $phase) {
            $value = $odds['moneyline'][$market][$phase]['odds'] ?? null;

            if (is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * The favorite: the side block flagged `favorite: true` (its own team
     * id, else that side's competitor), else the side the home-relative
     * line favors. A pick'em line gives null.
     *
     * @param  'home'|'away'|null  $favored
     */
    private function favoriteTeamId(array $odds, array $sides, ?string $favored): ?int
    {
        foreach (['homeTeamOdds' => 'home', 'awayTeamOdds' => 'away'] as $block => $side) {
            if (($odds[$block]['favorite'] ?? false) === true) {
                $id = $odds[$block]['team']['id'] ?? null;

                return is_numeric($id) ? (int) $id : $sides[$side]['id'];
            }
        }

        return $favored === null ? null : $sides[$favored]['id'];
    }
}
