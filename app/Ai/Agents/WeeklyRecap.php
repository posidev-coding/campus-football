<?php

namespace App\Ai\Agents;

use App\Enums\ContentRating;
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
 * The top of one reader's Tuesday email, written in that reader's own register.
 *
 * It is the ONLY thing here a model writes. The team rows below it — records,
 * scores, next kickoffs — stay assembled from the database, because a number a
 * model retyped is a number nobody can trace back to a source.
 *
 * NO TOOLS, deliberately. Everything it is allowed to say arrives in the
 * prompt. A recap that could search the web could contradict our own scoreboard
 * three paragraphs above itself, and the reader would have no way to tell which
 * half was ours.
 *
 * The register and the few-shot lines are constructor state rather than prompt
 * text because they are INSTRUCTIONS, not facts: what the app sounds like is
 * true of every reader at that level, while the prompt carries only this
 * reader's week.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-5')]
#[Timeout(25)]
class WeeklyRecap implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  list<string>  $exemplars  Lines already written in this register.
     */
    public function __construct(
        private readonly ContentRating $rating,
        private readonly array $exemplars,
    ) {}

    public function instructions(): Stringable|string
    {
        $register = $this->rating;
        $examples = implode("\n", array_map(fn (string $line): string => '  - '.$line, $this->exemplars));

        return <<<PROMPT
        You write the opening of a weekly college football email to one reader
        about the teams they follow. A headline and one to three short
        paragraphs. Nothing else — the scores, records and next kickoffs are
        laid out underneath what you write, so do not restate them as a list.

        RULES, all of them load-bearing:

        1. ONLY THE FACTS YOU ARE GIVEN. The prompt contains every true thing
           you may say: who they follow, what happened, what is next. You may
           not add a score, a rank, a record, a player, a coach, an injury, a
           betting line or a piece of history that is not in it. If the week was
           dull, say so — a dull week honestly described beats an invented
           storyline.

        2. ROAST THE PICK, THE TEAM, THE RECORD — NEVER THE PERSON. Their team
           can be a disaster. Their season can be a punchline. The reader
           cannot. Never tell them what they are, how they feel, or what is
           wrong with them, and never make the joke about anything to do with
           who they are.

        3. SOUND LIKE THIS. Every line below was written for this app at this
           reader's level. Match the rhythm and the confidence; do not quote
           them.

        {$examples}

        4. THE REGISTER IS {$register->label()} — {$register->subLabel()}.
           {$register->description()} Whatever the level, no profanity: this
           app's registers differ in attitude, not in vocabulary, which is what
           the lines above show.

        5. AMERICAN SPELLING. Favorite, color, center, canceled, traveled.

        6. PLAIN SENTENCES. No markdown, no headings, no bullet points, no
           emoji, no links. Each paragraph is at most three sentences, and the
           whole thing is read in under twenty seconds.

        Address the reader as "you" and their teams as "your". Use their first
        name at most once, and only if it lands.
        PROMPT;
    }

    /**
     * The mail template keeps control of layout, so the model returns PARTS —
     * never a rendered block. A single string would put paragraph breaks,
     * headings and emphasis inside the model's gift, and a markdown mailer
     * renders whatever it is handed.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()
                ->max(80)
                ->description('Six to ten words. The week in one line.')
                ->required(),

            'body' => $schema->array()
                ->items($schema->string()->max(320))
                ->min(1)
                ->max(3)
                ->description('One to three short paragraphs of plain prose.')
                ->required(),
        ];
    }
}
