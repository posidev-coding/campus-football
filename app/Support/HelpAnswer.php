<?php

namespace App\Support;

use App\Actions\RecordAiSpend;
use App\Ai\Agents\HelpQuestion;
use App\Enums\AiModel;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * A "how do I…?" answered out of our own copy.
 *
 * THE MODEL NEVER EMITS A FACT — {@see StatAnswer}'s rule on the surface that
 * explains the app. {@see HelpQuestion} names a TOPIC and {@see HelpTopics}
 * answers it from a line a person wrote, with the live numbers read from the
 * code the screens read. Nothing generated reaches a reader, so nothing here
 * needs a sweep, and the three registers survive.
 *
 * DECLINING IS CHEAP BY DESIGN. A miss hands the question to the feedback
 * form with one tap, so "we do not know" costs the reader nothing and tells
 * us what the topics are missing — which is how the list grows.
 *
 * The gates run cheapest-first, the same order as the stat answers: signed
 * in, flag config (mirrored, never Feature::active() per reader), the text
 * looks like a question, the reader is under the daily cap, and the budget
 * last because it is the only one that costs a query. The INTENT is cached a
 * day behind a normalized hash, and the limiter is hit inside the miss
 * branch, so the cap counts CALLS rather than taps.
 */
class HelpAnswer
{
    /** Model calls per reader per day. The cache means re-asks are free. */
    public const DAILY_CAP = 10;

    /**
     * Spelled out rather than derived — `now()->addDay()->diffInSeconds()`
     * is NEGATIVE in Carbon 3, which expires the key the instant it is written
     * and makes the cap permit everything.
     */
    private const WINDOW = 86400;

    /**
     * `v1` is load-bearing: the intent is a structured value on a day-class
     * TTL, so a change to its SHAPE bumps this in the same commit.
     */
    private const INTENT_KEY = 'ai:help:v1:';

    private const INTENT_TTL = 86400;

    /**
     * May this reader ask at all? Config only — this is read on every
     * signed-in page's render, and it must cost nothing.
     */
    public static function available(?User $user): bool
    {
        return $user !== null
            && config('cfb.ai_enabled') === true
            && config('cfb.ai_help') === true;
    }

    /**
     * Has this reader used today's questions up? Read only inside a tap —
     * never on render — because it is a limiter lookup.
     */
    public static function capped(?User $user): bool
    {
        return $user !== null
            && RateLimiter::tooManyAttempts(self::limiterKey($user), self::DAILY_CAP);
    }

    /**
     * What the doors say. Plain and identical in every register, so it lives
     * here rather than in Voice — the `searchPlaceholder()` precedent.
     */
    public static function doorLabel(?User $user): string
    {
        return self::available($user) ? 'Help & feedback' : 'Send feedback';
    }

    /** The rules page's door, which speaks to a reader who is already lost. */
    public static function stuckLabel(?User $user): string
    {
        return self::available($user) ? 'Still stuck? Ask a question' : 'Still stuck? Send feedback';
    }

    /**
     * The answer, or null and a developer reason for the log.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    public function for(string $question, ?User $user): array
    {
        if ($user === null || ! self::available($user)) {
            return [null, 'The question was not eligible to be asked'];
        }

        if (! StatAnswer::looksLikeAQuestion($question)) {
            return [null, 'The text does not read as a question'];
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

        if ($intent['answerable'] !== true) {
            return [null, $intent['note'] !== '' ? $intent['note'] : 'The question is not one the help topics answer'];
        }

        $answer = HelpTopics::answer($intent['topic'], $user);

        return $answer === null
            ? [null, "\"{$intent['topic']}\" is not a topic we answer"]
            : [$answer, 'resolved'];
    }

    /**
     * @return array{answerable: bool, topic: string, note: string}|null
     */
    private function intent(string $question, User $user): ?array
    {
        $key = self::INTENT_KEY.hash('sha256', $this->normalize($question));

        // Remember::filled, never Cache::remember: a failed call returns null
        // and caching that would pin "we cannot answer this" for a day over a
        // blip. `answerable: false` is a real answer and DOES cache.
        return Remember::filled($key, self::INTENT_TTL, function () use ($question, $user): ?array {
            // Hit HERE rather than at the door, so the cap counts CALLS.
            RateLimiter::hit(self::limiterKey($user), self::WINDOW);

            try {
                $response = (new HelpQuestion)->prompt($question);
            } catch (Throwable $e) {
                Log::warning('Help question not classified.', [
                    'failure' => AiFailure::classify($e),
                    'detail' => AiFailure::describe($e),
                ]);

                return null;
            }

            $this->recordSpend($response);

            return [
                'answerable' => ($response['answerable'] ?? false) === true,
                'topic' => (string) ($response['topic'] ?? ''),
                'note' => (string) ($response['note'] ?? ''),
            ];
        });
    }

    private function normalize(string $question): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($question))) ?? '', " \t\n?.!");
    }

    private static function limiterKey(User $user): string
    {
        return 'ai-help:'.$user->getKey();
    }

    /**
     * Deferred, because somebody is waiting on the answer and nobody is
     * waiting on our bookkeeping. Bookkeeping never breaks the product.
     */
    private function recordSpend(mixed $response): void
    {
        try {
            app(RecordAiSpend::class)->later(
                AiModel::Haiku45,
                'help',
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
