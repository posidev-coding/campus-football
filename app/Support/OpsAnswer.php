<?php

namespace App\Support;

use App\Actions\RecordAiSpend;
use App\Ai\Agents\OpsQuestion;
use App\Enums\AiModel;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An admin's sentence, answered out of the analytics catalog.
 *
 * THE MODEL NEVER EMITS A NUMBER — {@see StatAnswer}'s rule on the surface
 * that reads the product's own telemetry. {@see OpsQuestion} names one key
 * and one range; this runs {@see AnalyticsCatalog::answer()} and renders what
 * comes back. Nothing generated reaches the screen, so nothing here needs a
 * sweep, and there is no shape of failure that can put a made-up figure under
 * a real heading.
 *
 * NO PER-READER CAP, unlike the reader-facing answers. The door is a header
 * action on an admin-only page behind an `isAdmin()` gate, so the population
 * is the founder and the volume is trivial — {@see AiBudget} is the wall, and
 * a second limiter here would be a number to maintain that nothing could ever
 * reach.
 *
 * The gates run cheapest-first, the same order as the other two answers: the
 * flag config (mirrored, never `Feature::active()`), the text looks like a
 * question, and the budget last because it is the only one that costs a
 * query. The INTENT caches a day behind a normalized hash — the
 * CLASSIFICATION, never the numbers, which are recomputed on every ask so the
 * same question twice on the same day cannot answer from this morning.
 */
class OpsAnswer
{
    /**
     * `v1` is load-bearing: the intent is a structured value on a day-class
     * TTL, so a change to its SHAPE bumps this in the same commit.
     */
    private const INTENT_KEY = 'ai:ops:v1:';

    private const INTENT_TTL = 86400;

    /** What the modal says when nothing matched. Plain, and the same always. */
    public const MISS = 'Not a question we track.';

    /** Rows shown per table before "and N more" — a modal is not a dashboard. */
    public const TABLE_ROWS = 8;

    /**
     * May anybody ask at all? Config only — this is read on the page's every
     * render, so it must cost nothing.
     */
    public static function available(): bool
    {
        return config('cfb.ai_enabled') === true;
    }

    /**
     * The answer, or null and a developer reason for the log.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    public function for(string $question): array
    {
        if (! self::available()) {
            return [null, 'The AI layer is switched off'];
        }

        if (! StatAnswer::looksLikeAQuestion($question)) {
            return [null, 'The text does not read as a question'];
        }

        $budget = app(AiBudget::class);

        if (! $budget->allows()) {
            return [null, $budget->refusal() ?? 'The AI layer declined the call'];
        }

        $intent = $this->intent($question);

        if ($intent === null) {
            return [null, 'The classifier did not answer'];
        }

        if ($intent['answerable'] !== true) {
            return [null, $intent['note'] !== '' ? $intent['note'] : 'The question is not one the catalog answers'];
        }

        $answer = app(AnalyticsCatalog::class)->answer(
            $intent['question'],
            AnalyticsWindow::from(['range' => $intent['range']]),
        );

        return $answer === null
            ? [null, "\"{$intent['question']}\" is not a question we answer"]
            : [$this->render($answer, $question), 'resolved'];
    }

    /**
     * The answer as a screen reads it: a heading, the window it covers, the
     * day the data actually starts, and the numbers.
     *
     * `since` IS PART OF THE ANSWER AND NOT A FOOTNOTE. A 90-day count off a
     * sensor that shipped a fortnight ago is a two-week number wearing a
     * three-month label, and this surface exists to be trusted at a glance.
     *
     * @param  array{key: string, title: string, windowed: bool, data: array<string, mixed>}  $answer
     * @return array<string, mixed>
     */
    private function render(array $answer, string $asked): array
    {
        $data = $answer['data'];

        /*
         * FIVE OF THE ELEVEN ANSWER WITH A BARE LIST — the cohort grid, the
         * Saturdays, the weekday heat, the pick'em rows. They are one table
         * and nothing else, and a renderer that only walked maps drew them as
         * an empty modal.
         */
        $list = array_is_list($data);

        return [
            'asked' => $asked,
            'key' => $answer['key'],
            'title' => $answer['title'],
            /*
             * READ OFF THE PAYLOAD, never off a list kept here. A question
             * counted over a window says so by returning `window_days`, and
             * the ones counted in cohort weeks or Saturdays do not — so a
             * second list of "which of these are windowed" would be a map that
             * drifts, and its drift would print a range the numbers under it
             * do not honor.
             */
            'range' => isset($data['window_days']) ? $data['window_days'].' days' : null,
            /*
             * THREE STATES, NOT TWO. A question that does not report a `since`
             * at all says nothing about it; one that reports null means the
             * sensor has not counted a day in the window, which is a real and
             * different thing from a window full of zeroes.
             */
            'dated' => ! $list && array_key_exists('since', $data),
            'since' => $list ? null : ($data['since'] ?? null),
            'rows' => $list ? [] : self::rows($data),
            'tables' => $list
                ? self::tables([$answer['key'] => $data])
                : self::tables($data),
        ];
    }

    /**
     * Every scalar in the payload, flattened to label and value.
     *
     * NULL RENDERS AS THE WORDS, never as a zero and never as a blank: the
     * catalog returns null for "too few people to divide by", and a 0% there
     * is the most confident possible rendering of "we cannot tell yet".
     *
     * @param  array<string, mixed>  $data
     * @return list<array{label: string, value: string}>
     */
    public static function rows(array $data, string $prefix = ''): array
    {
        $rows = [];

        foreach ($data as $key => $value) {
            // The window and its start are the heading's, not the table's.
            if ($prefix === '' && in_array($key, ['since', 'window_days'], true)) {
                continue;
            }

            $label = trim($prefix.' '.str_replace('_', ' ', (string) $key));

            if (is_array($value)) {
                // A LIST of rows is a table, not a hundred flattened lines.
                if (! self::isMap($value)) {
                    continue;
                }

                $rows = [...$rows, ...self::rows($value, $label)];

                continue;
            }

            $rows[] = [
                'label' => ucfirst($label),
                'value' => match (true) {
                    $value === null => 'no data',
                    is_bool($value) => $value ? 'yes' : 'no',
                    is_float($value) => rtrim(rtrim(number_format($value, 3), '0'), '.'),
                    is_int($value) => number_format($value),
                    default => (string) $value,
                },
            ];
        }

        return $rows;
    }

    /**
     * The list-shaped parts, as small tables — the top routes, the cohort
     * grid, the Saturdays.
     *
     * Capped, because a modal is not a dashboard: the charts are on the pages
     * behind it and this is the answer to one sentence.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{label: string, columns: list<string>, rows: list<array<string, string>>, more: int}>
     */
    public static function tables(array $data): array
    {
        $tables = [];

        foreach ($data as $key => $value) {
            if (! is_array($value) || $value === [] || self::isMap($value)) {
                continue;
            }

            $first = reset($value);

            if (! is_array($first)) {
                continue;
            }

            $shown = array_slice($value, 0, self::TABLE_ROWS);

            $tables[] = [
                'label' => ucfirst(str_replace('_', ' ', (string) $key)),
                'columns' => array_map(
                    fn (string $column): string => ucfirst(str_replace('_', ' ', $column)),
                    array_keys($first),
                ),
                'rows' => array_map(
                    fn (array $row): array => array_map(
                        fn (mixed $cell): string => match (true) {
                            $cell === null => 'no data',
                            is_bool($cell) => $cell ? 'yes' : 'no',
                            is_array($cell) => (string) count($cell),
                            is_float($cell) => rtrim(rtrim(number_format($cell, 3), '0'), '.'),
                            is_int($cell) => number_format($cell),
                            default => (string) $cell,
                        },
                        $row,
                    ),
                    $shown,
                ),
                'more' => max(0, count($value) - self::TABLE_ROWS),
            ];
        }

        return $tables;
    }

    /** A string-keyed array is a nested reading; a list is a table. */
    private static function isMap(array $value): bool
    {
        return $value !== [] && ! array_is_list($value);
    }

    /**
     * @return array{answerable: bool, question: string, range: string, note: string}|null
     */
    private function intent(string $question): ?array
    {
        $key = self::INTENT_KEY.hash('sha256', $this->normalize($question));

        // Remember::filled, never Cache::remember: a failed call returns null
        // and caching that would pin "we cannot answer this" for a day over a
        // blip. `answerable: false` is a real answer and DOES cache.
        return Remember::filled($key, self::INTENT_TTL, function () use ($question): ?array {
            try {
                $response = (new OpsQuestion)->prompt($question);
            } catch (Throwable $e) {
                Log::warning('Ops question not classified.', [
                    'failure' => AiFailure::classify($e),
                    'detail' => AiFailure::describe($e),
                ]);

                return null;
            }

            $this->recordSpend($response);

            return [
                'answerable' => ($response['answerable'] ?? false) === true,
                'question' => (string) ($response['question'] ?? ''),
                'range' => (string) ($response['range'] ?? AnalyticsWindow::DEFAULT_RANGE),
                'note' => (string) ($response['note'] ?? ''),
            ];
        });
    }

    private function normalize(string $question): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($question))) ?? '', " \t\n?.!");
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
                'ops',
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
