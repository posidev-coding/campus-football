<?php

namespace App\Support;

use App\Actions\RecordAiSpend;
use App\Ai\Agents\WeeklyRecap;
use App\Enums\AiModel;
use App\Jobs\SendWeeklyNewsletter;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The top of one reader's Tuesday email, or null — which means the email sends
 * the way it has always sent.
 *
 * NULL IS THE WHOLE DESIGN. Every branch that cannot produce a good recap
 * returns it, and the template answers by rendering the deterministic
 * `mail.newsletter` copy that shipped months before any of this: real content
 * a human wrote, not an invented substitute and not an error page in an inbox.
 * A reader whose recap failed gets last month's email; a reader whose recap
 * failed loudly would get nothing.
 *
 * Runs INSIDE {@see SendWeeklyNewsletter}, which is per-user and
 * carries a 60-second timeout — so the agent's own timeout is 25, leaving the
 * mail send the rest. It is also why the failure path costs one reader their
 * recap rather than the send its batch.
 */
class RecapWriter
{
    /**
     * @param  array{teams: list<array<string, mixed>>, since: mixed, has_results: bool}  $digest
     * @return array{headline: string, body: list<string>}|null
     */
    public function for(User $user, array $digest): ?array
    {
        /*
         * The flag's VALUE, never `Feature::active()`.
         *
         * This runs once per reader inside a fan-out, and Pennant's database
         * driver persists a row per resolve — so asking it here would write
         * one row per subscriber every Tuesday and then answer from those
         * stale rows after the flag was flipped back. Mirroring config is what
         * `pickem:preflight` does, for the same reason.
         */
        if (config('cfb.ai_recaps') !== true) {
            return null;
        }

        // Nobody played. The empty-week email is a different email, and it is
        // already written — there is nothing here to recap.
        if (($digest['has_results'] ?? false) !== true) {
            return null;
        }

        $facts = $this->facts($user, $digest);

        if ($facts === '') {
            return null;
        }

        $budget = app(AiBudget::class);

        /*
         * ONE question covering the master switch and the ceiling, asked
         * BEFORE the call while there is still deterministic copy to serve
         * instead of an error. The same doorway the GameDay fallback uses.
         */
        if (! $budget->allows()) {
            return null;
        }

        $rating = $user->content_rating;

        try {
            $response = (new WeeklyRecap($rating, Voice::exemplars($rating)))
                ->prompt($this->prompt($user, $digest, $facts));
        } catch (Throwable $e) {
            /*
             * Both spend-limit shapes land here, and neither is retryable —
             * which is fine, because nothing retries: the reader gets the
             * deterministic email and the send carries on. AiFailure is what
             * makes the log say which wall we hit.
             */
            Log::warning('Weekly recap not generated.', [
                'user' => $user->getKey(),
                'failure' => AiFailure::classify($e),
                'detail' => AiFailure::describe($e),
            ]);

            return null;
        }

        $this->recordSpend($response);

        $recap = $this->normalize($response);

        if ($recap === null) {
            return null;
        }

        $reasons = app(RecapSweep::class)->reasons($recap, $rating, $facts);

        if ($reasons !== []) {
            Log::warning('Weekly recap failed the sweep.', [
                'user' => $user->getKey(),
                'reasons' => $reasons,
            ]);

            return null;
        }

        return $recap;
    }

    /**
     * Everything the model is allowed to say, and nothing else.
     *
     * Assembled from the SAME digest the rows below the recap render from, so
     * the two halves of the email cannot disagree — and phrased as absence
     * rather than as a default where there is no data: "did not play" is a
     * fact, and a zero would have been a fabrication.
     *
     * @param  array{teams: list<array<string, mixed>>, since: mixed, has_results: bool}  $digest
     */
    private function facts(User $user, array $digest): string
    {
        $lines = [];

        foreach ($digest['teams'] as $row) {
            $team = $row['team'];

            if (! $team instanceof Team) {
                continue;
            }

            $heading = $row['rank'] ? '#'.$row['rank'].' ' : '';
            $heading .= $team->display_name;
            $heading .= $row['record'] ? ' ('.$row['record'].')' : '';

            $lines[] = $heading;
            $lines[] = '  Last week: '.($row['result'] instanceof Game
                ? WeeklyDigest::describe($row['result'], $team)
                : 'did not play');
            $lines[] = '  Next: '.$this->nextLine($row['next'] ?? null, $user);
        }

        return implode("\n", $lines);
    }

    private function nextLine(mixed $next, User $user): string
    {
        if (! $next instanceof Game) {
            return 'nothing scheduled yet';
        }

        $matchup = ($next->awayTeam?->placeName() ?? 'TBD').' at '.($next->homeTeam?->placeName() ?? 'TBD');

        // The reader's own timezone, the way the rows below do it. A kickoff
        // stated an hour out is worse than no kickoff: it is wrong in a way
        // that looks authoritative.
        $kickoff = $next->kickoff_at?->setTimezone($user->timezone)->format('D j M, g:ia');

        return $kickoff === null ? $matchup.', time to be announced' : $matchup.', '.$kickoff;
    }

    /**
     * @param  array{teams: list<array<string, mixed>>, since: mixed, has_results: bool}  $digest
     */
    private function prompt(User $user, array $digest, string $facts): string
    {
        $since = $digest['since'];
        $window = $since instanceof CarbonInterface
            ? $since->format('F j').' to '.now(config('cfb.timezone'))->format('F j')
            : 'the past week';

        return <<<PROMPT
        Reader's first name: {$user->first_name}
        The week just finished: {$window}

        THE FACTS. This is every true thing you may say.

        {$facts}
        PROMPT;
    }

    /**
     * The response reduced to two plain values, or null when it is not the
     * shape we asked for.
     *
     * Structured output is a constraint, not a guarantee we get to skip
     * checking — and the sweep below expects strings.
     *
     * @return array{headline: string, body: list<string>}|null
     */
    private function normalize(mixed $response): ?array
    {
        $headline = $response['headline'] ?? null;
        $body = $response['body'] ?? null;

        if (! is_string($headline) || ! is_array($body)) {
            return null;
        }

        return [
            'headline' => trim($headline),
            'body' => array_values(array_map(
                fn (mixed $paragraph): string => is_string($paragraph) ? trim($paragraph) : '',
                $body,
            )),
        ];
    }

    /**
     * Charged whatever the sweep then decides, because the tokens were spent
     * either way — a budget that only counts the calls it liked undercounts
     * exactly when something is going wrong.
     */
    private function recordSpend(mixed $response): void
    {
        try {
            app(RecordAiSpend::class)->handle(
                AiModel::Sonnet5,
                'recap',
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
