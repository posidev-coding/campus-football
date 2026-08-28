<?php

namespace App\Support;

use App\Actions\RecordAiSpend;
use App\Ai\Agents\StatQuestion;
use App\Enums\AiModel;
use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteSeasonStat;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeasonStat;
use App\Models\User;
use App\Services\CfbCalendar;
use App\Services\Stats\AggregateAthleteStats;
use App\Support\Stats\LeaderQuery;
use App\Support\Stats\StatCatalog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * A typed question, answered out of our own tables.
 *
 * THE MODEL NEVER EMITS A FACT — the Phase 7 rule on the surface it was written
 * for. {@see StatQuestion} returns an INTENT ("passing yards, for somebody they
 * called Brandon Faizon, over a season") and everything below decides whether
 * that person exists, which season, and what the number is. A hallucinated stat
 * line cannot reach a screen because there is nowhere in the intent to put one.
 *
 * STRICTLY ADDITIVE. The answer only ever runs where ordinary search found
 * nothing, so it cannot displace a good result — it turns the one dead end this
 * app has into an answer, and when it declines the reader is exactly where they
 * were. That is also why declining is cheap enough to be the default: the cost
 * of "we do not know" here is zero.
 *
 * Four gates before a penny is spent, cheapest first: signed in, flag open, the
 * text looks like a question, and the reader is under their daily cap. The
 * budget is asked last because it is the only one that costs a query.
 */
class StatAnswer
{
    /** Model calls per reader per day. The cache means re-asks are free. */
    public const DAILY_CAP = 10;

    /**
     * The limiter window, spelled out rather than derived — `now()->addDay()
     * ->diffInSeconds()` is NEGATIVE 86400 in Carbon 3, which expires the key
     * the instant it is written and makes the cap permit everything.
     */
    private const WINDOW = 86400;

    /**
     * The INTENT is cached, not the answer: a question means the same thing
     * tomorrow, while the number behind it moves every Saturday. A pilot all
     * asking about the same game collapses to one call.
     *
     * `v1` is load-bearing. The intent is a structured value on a day-class
     * TTL, so a change to its SHAPE must bump this in the same commit or every
     * reader holds yesterday's shape for a day.
     */
    private const INTENT_KEY = 'ai:intent:v1:';

    private const INTENT_TTL = 86400;

    /** Below this, nothing is a question. */
    private const MIN_LENGTH = 12;

    /** @var list<string> */
    private const INTERROGATIVES = [
        'how', 'who', 'what', 'which', 'when', 'where', 'why', 'whose',
        'did', 'does', 'do', 'is', 'are', 'was', 'were', 'has', 'have', 'can',
    ];

    /**
     * Worth OFFERING to answer — free, deterministic, and no model involved.
     *
     * Deliberately does not ask the budget or the cap. Offering costs nothing,
     * and the two states that DO refuse are worth saying out loud rather than
     * expressing as an affordance that quietly fails to appear.
     */
    public static function askable(string $question, ?User $user): bool
    {
        /*
         * Guests get today's Search, unchanged — see available(). Reading is
         * never gated in this app, but an answer is a COMPUTATION rather than
         * a reading, and it carries a bill an anonymous session cannot be
         * capped against.
         */
        return self::available($user) && self::looksLikeAQuestion($question);
    }

    /**
     * May this reader ask ANYTHING — before there is a question to judge?
     *
     * Split out because the surface needs it on an empty screen: the example
     * questions that teach the feature exist are shown on the same terms the
     * feature itself is, so nobody is ever offered a tap they cannot take.
     */
    public static function available(?User $user): bool
    {
        return $user !== null
            && config('cfb.ai_enabled') === true
            && config('cfb.ai_answers') === true;
    }

    /**
     * Has this reader used today's questions up?
     *
     * Separate from `askable()` so the surface can SAY so. An affordance that
     * silently stops appearing reads as the feature breaking, and the eleventh
     * question of the day is exactly when somebody is getting value from it.
     */
    public static function capped(?User $user): bool
    {
        return $user !== null
            && RateLimiter::tooManyAttempts(self::limiterKey($user), self::DAILY_CAP);
    }

    /**
     * Does this read like a question rather than a name?
     *
     * The one gate that runs on every keystroke, so it stays string work. It is
     * generous on purpose — everything expensive is behind an explicit tap, and
     * an offer that fails to appear for a real question is the worse error.
     */
    public static function looksLikeAQuestion(string $question): bool
    {
        $question = trim($question);

        if (mb_strlen($question) < self::MIN_LENGTH) {
            return false;
        }

        if (str_ends_with($question, '?')) {
            return true;
        }

        $words = preg_split('/\s+/', $question) ?: [];

        return count($words) >= 5
            || in_array(mb_strtolower($words[0] ?? ''), self::INTERROGATIVES, true);
    }

    /**
     * Does this ASK something, rather than merely being long enough to?
     *
     * The distinction earns its keep on the surface. An outright question — a
     * question mark, or an interrogative anywhere in it — is offered an answer
     * even when search found rows, because "Mensah passing yards?" matches a
     * player AND wants a number, and the reader who typed it is the one this
     * feature is for. A query that qualifies only by LENGTH is offered nothing
     * while results exist: "Tennessee Volunteers at Kentucky Wildcats" is five
     * words and a fixture, not a question.
     */
    public static function asksOutright(string $question): bool
    {
        $question = trim($question);

        if (mb_strlen($question) < self::MIN_LENGTH) {
            return false;
        }

        if (str_contains($question, '?')) {
            return true;
        }

        foreach (preg_split('/\s+/', mb_strtolower($question)) ?: [] as $word) {
            if (in_array(trim($word, ".,!'\""), self::INTERROGATIVES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The answer, or null and a developer reason for the log.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    public function for(string $question, ?User $user): array
    {
        if ($user === null || ! self::askable($question, $user)) {
            return [null, 'The question was not eligible to be asked'];
        }

        if (self::capped($user)) {
            return [null, 'The reader is over their daily cap of '.self::DAILY_CAP];
        }

        $budget = app(AiBudget::class);

        if (! $budget->allows()) {
            return [null, $budget->refusal() ?? 'The AI layer declined the call'];
        }

        $intent = $this->intent($question, $user);

        if ($intent === null) {
            return [null, 'The classifier did not answer'];
        }

        return $this->resolve($intent);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function intent(string $question, User $user): ?array
    {
        $key = self::INTENT_KEY.hash('sha256', $this->normalize($question));

        /*
         * Remember::filled, never Cache::remember: a failed call returns null
         * and caching that would pin "we cannot answer this" for a day over a
         * blip. `answerable: false` is a real answer and DOES cache — it is a
         * fact about the question, not a fact about the weather.
         */
        return Remember::filled($key, self::INTENT_TTL, function () use ($question, $user): ?array {
            // Hit HERE rather than at the door, so the cap counts CALLS. A
            // reader re-asking something already resolved costs nothing and
            // should be charged nothing.
            RateLimiter::hit(self::limiterKey($user), self::WINDOW);

            try {
                $response = (new StatQuestion)->prompt($question);
            } catch (Throwable $e) {
                Log::warning('Stat question not classified.', [
                    'failure' => AiFailure::classify($e),
                    'detail' => AiFailure::describe($e),
                ]);

                return null;
            }

            $this->recordSpend($response);

            return [
                'answerable' => ($response['answerable'] ?? false) === true,
                'subject' => (string) ($response['subject'] ?? ''),
                'name' => trim((string) ($response['name'] ?? '')),
                'metric' => (string) ($response['metric'] ?? ''),
                'timeframe' => (string) ($response['timeframe'] ?? 'season'),
                'season_year' => is_numeric($response['season_year'] ?? null) ? (int) $response['season_year'] : null,
                'note' => (string) ($response['note'] ?? ''),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function resolve(array $intent): array
    {
        if (($intent['answerable'] ?? false) !== true) {
            return [null, $intent['note'] !== '' ? $intent['note'] : 'The question is not answerable from our data'];
        }

        $subject = $intent['subject'];

        /*
         * THE VOCABULARY IS PER SUBJECT, and asking the wrong one declines.
         * Player and team stats share names — `rushingYards` is a person's
         * total and a program's total — so a team metric aimed at a player
         * would read a row that does not exist, or worse, one that does.
         */
        $vocabulary = $subject === 'team'
            ? StatCatalog::answerable(team: true)
            : StatCatalog::answerable();

        $board = $vocabulary[$intent['metric']] ?? null;

        if ($board === null) {
            return [null, "\"{$intent['metric']}\" is not a metric we can look up for a {$subject}"];
        }

        $year = $this->year($intent['season_year']);

        return match ($subject) {
            'player' => $intent['timeframe'] === 'last_game'
                ? $this->playerGame($intent['name'], $board)
                : $this->playerSeason($intent['name'], $board, $year),
            'team' => $this->teamSeason($intent['name'], $board, $year),
            'leaders' => $this->leaders($board, $year),
            default => [null, "\"{$subject}\" is not a subject we answer for"],
        };
    }

    /**
     * @param  array<string, mixed>  $board
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function playerSeason(string $name, array $board, int $year): array
    {
        $athlete = $this->athlete($name);

        if ($athlete === null) {
            return [null, "No single player we hold answers to \"{$name}\""];
        }

        $stats = AthleteSeasonStat::query()
            ->where('athlete_id', $athlete->id)
            ->where('season_year', $year)
            // The whole year, bowls included — the same series the leaderboards
            // rank, so an answer and the board it appears on cannot disagree.
            ->where('season_type', AggregateAthleteStats::FULL_SEASON)
            ->where('category', $board['category'])
            ->first()
            ?->stats ?? [];

        $value = $stats[$board['stat']] ?? null;

        if (! is_numeric($value)) {
            return [null, "We hold no {$board['label']} for {$athlete->display_name} in {$year}"];
        }

        return [[
            'kind' => 'value',
            'label' => $board['label'],
            'value' => $this->format((float) $value, $board['decimals'] ?? 0),
            'name' => $athlete->display_name,
            'href' => route('player', $athlete),
            'context' => $year.' season',
        ], 'resolved'];
    }

    /**
     * The player's most recent COMPLETED game, named in the answer.
     *
     * Deliberately not "last week". A week is a thing this app resolves three
     * different ways, and a reader asking about "last week" during a bye means
     * the last time he played. Reporting the game by name makes any mismatch
     * visible instead of silent — which a resolved week number never would.
     *
     * @param  array<string, mixed>  $board
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function playerGame(string $name, array $board): array
    {
        $athlete = $this->athlete($name);

        if ($athlete === null) {
            return [null, "No single player we hold answers to \"{$name}\""];
        }

        $columns = 'id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark';

        $row = AthleteGameStat::query()
            ->where('athlete_game_stats.athlete_id', $athlete->id)
            ->where('athlete_game_stats.category', $board['category'])
            ->join('games', 'games.id', '=', 'athlete_game_stats.game_id')
            ->where('games.completed', true)
            // kickoff_at, never kickoff_day — that column stores a WEEKDAY
            // NAME ("Sat"), so ordering by it sorts alphabetically.
            ->orderByDesc('games.kickoff_at')
            // Qualified, or `id` is ambiguous across the join and MySQL
            // rejects the query outright.
            ->select('athlete_game_stats.*')
            ->with(['game.homeTeam:'.$columns, 'game.awayTeam:'.$columns])
            ->first();

        $value = ($row?->stats ?? [])[$board['stat']] ?? null;

        if ($row === null || ! is_numeric($value)) {
            return [null, "We hold no recent {$board['label']} for {$athlete->display_name}"];
        }

        return [[
            'kind' => 'value',
            'label' => $board['label'],
            'value' => $this->format((float) $value, $board['decimals'] ?? 0),
            'name' => $athlete->display_name,
            'href' => route('player', $athlete),
            'context' => $this->gameContext($row),
        ], 'resolved'];
    }

    /**
     * @param  array<string, mixed>  $board
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function teamSeason(string $name, array $board, int $year): array
    {
        $team = $this->team($name);

        if ($team === null) {
            return [null, "No single team we hold answers to \"{$name}\""];
        }

        $row = TeamSeasonStat::query()
            ->where('team_id', $team->id)
            ->where('season_year', $year)
            // Team stats arrive from ESPN per season type, and the regular
            // season is the only complete series we hold for them.
            ->where('season_type', Season::REGULAR)
            ->where('category', $board['category'])
            ->first();

        $stat = $row?->stat($board['stat']);

        if ($stat === null || $stat['display'] === null) {
            return [null, "We hold no {$board['label']} for {$team->display_name} in {$year}"];
        }

        return [[
            'kind' => 'value',
            'label' => $board['label'],
            'value' => $stat['display'],
            'name' => $team->display_name,
            'href' => route('team', $team),
            'context' => $year.' season'.($stat['rank'] ? ' · '.Ordinal::of((int) $stat['rank']).' nationally' : ''),
        ], 'resolved'];
    }

    /**
     * @param  array<string, mixed>  $board
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function leaders(array $board, int $year): array
    {
        // FBS rather than everybody: ESPN's own national list spans every
        // division, and a board half full of names nobody recognizes reads as
        // broken rather than as a wider net.
        $rows = LeaderQuery::players($board, $year, Scope::FBS, limit: 5);

        if ($rows === []) {
            return [null, "We hold no {$board['label']} leaders for {$year}"];
        }

        $athletes = Athlete::query()
            ->whereIn('id', collect($rows)->pluck('athlete_id'))
            ->get(['id', 'slug', 'display_name'])
            ->keyBy('id');

        $teams = Team::query()
            ->whereIn('id', collect($rows)->pluck('team_id')->filter())
            ->get(['id', 'abbreviation'])
            ->keyBy('id');

        $leaders = collect($rows)
            ->map(function (array $row) use ($athletes, $teams): ?array {
                $athlete = $athletes[$row['athlete_id']] ?? null;

                // A leader we cannot name is dropped rather than shown as a
                // blank row — an aggregate can outlive the athlete row behind
                // it, and a rank with nobody in it looks like a bug.
                return $athlete === null ? null : [
                    'rank' => $row['rank'],
                    'name' => $athlete->display_name,
                    'team' => $teams[$row['team_id']]->abbreviation ?? null,
                    'href' => route('player', $athlete),
                    'value' => $row['display'],
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($leaders === []) {
            return [null, "We hold no nameable {$board['label']} leaders for {$year}"];
        }

        return [[
            'kind' => 'leaders',
            'label' => $board['label'],
            'context' => $year.' season · FBS',
            'rows' => $leaders,
        ], 'resolved'];
    }

    /**
     * A named year only if we actually hold that season; otherwise the latest
     * one with games PLAYED.
     *
     * `resultsYear()`, never `currentYear()`. In August those differ and the
     * current one has no games in it at all — every answer would be "we hold
     * nothing", which reads as the feature being broken rather than as August.
     */
    private function year(?int $named): int
    {
        if ($named !== null && Season::query()->where('year', $named)->exists()) {
            return $named;
        }

        return app(CfbCalendar::class)->resultsYear();
    }

    /**
     * Exactly one person, or nobody.
     *
     * An exact name wins outright; otherwise only an unambiguous single match
     * is safe. Taking the top row of an ambiguous search is how "Smith" becomes
     * a confident answer about the wrong Smith, and the reader has no way to
     * tell — the number would look perfectly reasonable.
     */
    private function athlete(string $name): ?Athlete
    {
        $matches = Search::players($name, limit: 4);

        $exact = $matches->filter(
            fn (Athlete $athlete): bool => $this->same($athlete->display_name, $name)
        );

        if ($exact->count() === 1) {
            return $exact->first();
        }

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * The same rule for teams, with four names to match on rather than one.
     *
     * A school is habitually written short — "Ohio State" for the Buckeyes —
     * so an exact hit on ANY of the names we hold counts as exact. A nickname
     * we do not hold ("the Vols") declines rather than guesses; the reader
     * still has the ordinary results underneath.
     */
    private function team(string $name): ?Team
    {
        $matches = Search::teams($name, limit: 4);

        $exact = $matches->filter(fn (Team $team): bool => $this->same($team->display_name, $name)
            || $this->same($team->location, $name)
            || $this->same($team->short_display_name, $name)
            || $this->same($team->abbreviation, $name));

        if ($exact->count() === 1) {
            return $exact->first();
        }

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function same(?string $a, string $b): bool
    {
        return $a !== null && mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    private function gameContext(AthleteGameStat $row): string
    {
        $game = $row->game;

        if ($game === null) {
            return 'most recent game';
        }

        $home = $game->home_team_id === $row->team_id;
        $opponent = $home ? $game->awayTeam : $game->homeTeam;

        $when = $game->kickoff_at?->setTimezone(config('cfb.timezone'))->format('M j');

        return trim(($home ? 'vs ' : 'at ').($opponent?->placeName() ?? 'TBD').($when === null ? '' : ' · '.$when));
    }

    private function format(float $value, int $decimals): string
    {
        return number_format($value, $decimals);
    }

    private function normalize(string $question): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($question))) ?? '', " \t\n?.!");
    }

    private static function limiterKey(User $user): string
    {
        return 'ai-answer:'.$user->getKey();
    }

    /**
     * Deferred, because somebody is waiting on the answer and nobody is
     * waiting on our bookkeeping. `later()` rather than `handle()` is the
     * request-path half of RecordAiSpend's two doors.
     */
    private function recordSpend(mixed $response): void
    {
        try {
            app(RecordAiSpend::class)->later(
                AiModel::Haiku45,
                'answer',
                $response->usage->promptTokens,
                $response->usage->completionTokens,
                $response->usage->cacheWriteInputTokens,
                $response->usage->cacheReadInputTokens,
            );
        } catch (Throwable) {
            // Bookkeeping never breaks the product.
        }
    }
}
