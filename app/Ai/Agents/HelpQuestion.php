<?php

namespace App\Ai\Agents;

use App\Support\HelpTopics;
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
 * Sorts a reader's "how do I…?" into ONE help topic. It never answers.
 *
 * The rule {@see StatQuestion} established, on the surface that explains the
 * app rather than the season: the model names WHICH topic, and the
 * application shows the answer a person wrote for it. A hallucinated rule
 * about money or deadlines cannot reach a screen because there is nowhere in
 * the schema to put one — the only thing the model can emit is a key from a
 * list we hold, and every key resolves to copy we wrote.
 *
 * NO TOOLS. The whole job is the sentence and the vocabulary.
 *
 * Haiku, and comfortably so: this is classification against a closed list,
 * the cheapest thing in the layer.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5')]
#[Timeout(15)]
class HelpQuestion implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $topics = HelpTopics::vocabulary();

        return <<<PROMPT
        You sort a question about a college football pick'em app into the ONE
        help topic that answers it. You never answer the question yourself —
        the application shows its own written answer for the topic you name.
        Your entire job is naming WHICH topic.

        THE TOPICS. `topic` must be one of these keys exactly:

        {$topics}

        THE FIELDS:

        - `answerable` — true when one topic above answers the question.
        - `topic` — that key. Choose the topic whose ANSWER the reader needs,
          not the one that shares the most words with the question: "when do
          picks lock" is `picks.lock`; "when is the slate due" is
          `groups.slate`; "how does the tiebreaker work" is `picks.tiebreaker`;
          "how do I get more Tallboys" is `wallet.tallboys` while "how do I put
          a Tallboy on a game" is `picks.tallboy`.
        - `note` — why it cannot be answered, when it cannot.

        SET answerable=false, AND PUT THE REASON IN `note`, WHEN:

        - the question asks for a score, a statistic, a ranking, a schedule or
          anything about the football itself rather than about using this app
          — Search answers those;
        - it asks for a prediction, an opinion, or which team to pick;
        - it is not about this app at all, or you cannot tell what is being
          asked.

        UNANSWERABLE IS A GOOD ANSWER. The reader is offered a way to send the
        question to a person instead, so declining costs them nothing. Naming a
        topic that is merely nearby costs them an answer to a question they did
        not ask.
        PROMPT;
    }

    /**
     * `topic` is an enum but NOT required, the way {@see StatQuestion}'s
     * metric is: an unanswerable question has no topic, and the resolver
     * refuses an empty or unknown one rather than the schema forcing a guess.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'answerable' => $schema->boolean()->required(),

            'topic' => $schema->string()
                ->enum(HelpTopics::keys())
                ->description('One key from the topics.'),

            'note' => $schema->string()
                ->description('Why this cannot be answered, when it cannot.'),
        ];
    }
}
