<?php

namespace App\Ai\Agents;

use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Sorts an admin's question about the app's own numbers into ONE named
 * question and one window. It never emits a number, and it never emits SQL.
 *
 * The rule {@see StatQuestion} established and {@see HelpQuestion} repeated,
 * on the surface that reads the product's own telemetry — and the reason
 * `docs/plans/analytics.md` rejects the Data Copilot plugin, which sends a
 * schema to a model, executes the SQL that comes back, and sends the ROWS
 * back for narrative. There is nowhere in this schema to put a number: the
 * only things the model can say are a key from a list we hold and a range
 * token from {@see AnalyticsWindow}, and the application runs the query.
 *
 * NO TOOLS, and no data in the prompt either — the vocabulary is the whole
 * context, so nobody's rows ever leave the database.
 *
 * Haiku, and comfortably so: classification against a closed list of eleven.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5')]
#[Timeout(15)]
class OpsQuestion implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $questions = AnalyticsCatalog::vocabulary();
        $ranges = implode(', ', array_map(
            fn (string $token, string $label): string => "`{$token}` ({$label})",
            array_keys(AnalyticsWindow::options()),
            array_values(AnalyticsWindow::options()),
        ));

        $defaultRange = AnalyticsWindow::DEFAULT_RANGE;

        return <<<PROMPT
        You sort an administrator's question about a college football pick'em
        app's OWN usage numbers into the ONE named question that answers it.
        You never answer the question yourself, you never state a number, and
        you never write a query — the application runs the named question and
        renders its own answer. Your entire job is naming WHICH question and
        over what window.

        THE QUESTIONS. `question` must be one of these keys exactly:

        {$questions}

        THE RANGES. `range` must be one of: {$ranges}. Default to
        `{$defaultRange}` when the asker does not say. Some questions ignore
        the range entirely because they are counted in cohort weeks or in
        Saturdays; name one anyway and the application will disregard it.

        THE FIELDS:

        - `answerable` — true when one question above answers it.
        - `question` — that key. Choose the one whose ANSWER is wanted, not
          the one sharing the most words: "how many people are using it" is
          `actives` while "how many pages are being read" is `traffic`;
          "which screens does nobody open" is `routes`; "are the people who
          signed up in August still here" is `retention` while "did last
          week's players come back Saturday" is `saturday_retention`; "how
          many signed up last week" is `cohorts`.
        - `range` — the window token.
        - `note` — why it cannot be answered, when it cannot.

        SET answerable=false, AND PUT THE REASON IN `note`, WHEN:

        - the question is about football — a score, a ranking, a schedule, a
          player — rather than about how this app is being used;
        - it asks about ONE named person, group or account. Nothing here
          answers at that resolution and nothing should;
        - it asks for a prediction, a recommendation, a cause, or an opinion
          about what the numbers mean;
        - it asks for a number no listed question reports, or you cannot tell
          what is being asked.

        DECLINING IS THE GOOD ANSWER when you are unsure. The dashboards are
        right there, so a decline costs the asker a scroll. Naming a question
        that is merely nearby puts a real number under the wrong heading,
        which is worse than no answer.
        PROMPT;
    }

    /**
     * `question` and `range` are enums but NOT required, the way
     * {@see HelpQuestion}'s topic is: an unanswerable question names
     * neither, and the resolver refuses an empty or unknown one rather than
     * the schema forcing a guess.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'answerable' => $schema->boolean()->required(),

            'question' => $schema->string()
                ->enum(AnalyticsCatalog::keys())
                ->description('One key from the questions.'),

            'range' => $schema->string()
                ->enum(array_keys(AnalyticsWindow::options()))
                ->description('The window token to answer over.'),

            'note' => $schema->string()
                ->description('Why this cannot be answered, when it cannot.'),
        ];
    }
}
