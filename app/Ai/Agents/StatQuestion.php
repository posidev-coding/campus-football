<?php

namespace App\Ai\Agents;

use App\Support\Stats\StatCatalog;
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
 * Turns a typed question into an INTENT. It never answers one.
 *
 * The rule Phase 7 established, applied to the surface it was written for: the
 * model never emits a fact. It says "this person is asking for passing yards,
 * for somebody they called Brandon Faizon, over a season" — and the app decides
 * whether that person exists, which season, and what the number is. Every field
 * below is a claim our own database gets to overrule, and a hallucinated stat
 * line cannot reach a screen because there is nowhere in the schema to put one.
 *
 * NO TOOLS and no search. Everything it needs is the sentence and the
 * vocabulary; anything it might look up, we already hold.
 *
 * Haiku, and comfortably so. This is classification against a closed list of
 * 45 metrics — the cheapest thing in the whole layer, and the one asked most
 * often. Prose gets Sonnet; sorting a sentence into a bucket does not.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5')]
#[Timeout(15)]
class StatQuestion implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $players = StatCatalog::vocabulary();
        $teams = StatCatalog::vocabulary(team: true);

        return <<<PROMPT
        You sort a college football question into a lookup this app can perform.
        You never answer the question, and you never state a statistic — the
        application reads the number out of its own database. Your entire job is
        naming WHAT was asked for.

        THE VOCABULARY. `metric` must be one of these exactly, and the choice is
        the whole point of the field: the category and the stat travel together
        because the same word means opposite things in two categories.
        `interceptions.interceptions` is picks CAUGHT by a defender;
        `passing.passingTouchdowns` is thrown. Interceptions THROWN are not in
        this list, so a question about them is not answerable.

        PLAYER METRICS
        {$players}

        TEAM METRICS
        {$teams}

        THE FIELDS:

        - `subject` — `player` for one person, `team` for one team, `leaders`
          for "who leads / who has the most".
        - `name` — the person or team EXACTLY as the reader wrote it. Do not
          correct a spelling, expand a nickname from memory, or supply a school
          you think they meant. The app resolves the name against its own
          records and will decline what it cannot find; a name you improved is
          a name it cannot match. Leave empty for `leaders`.
        - `metric` — one key from the lists above.
        - `timeframe` — `season` for a whole season, `last_game` for "last
          week", "his last game", "on Saturday". `last_game` is only valid for
          `subject: player`; use `season` for everything else.
        - `season_year` — only when the reader named a literal four-digit year.
          Otherwise null: the app knows which season is current, says which one
          it used, and is right more often than an inference from "last year".

        SET answerable=false, AND PUT THE REASON IN `note`, WHEN:

        - the metric asked for is not in the lists above — including anything
          about game quality, which is three different numbers in this app and
          is deliberately not answerable;
        - the question wants an opinion, a prediction, a comparison, a ranking
          this app does not keep, or anything that is not a single stored
          number;
        - it is not a football question at all, or you cannot tell what is
          being asked.

        UNANSWERABLE IS A GOOD ANSWER. The reader still gets the ordinary
        search results underneath, so declining costs them nothing. Guessing a
        metric that is merely nearby costs them a wrong number with our name on
        it.
        PROMPT;
    }

    /**
     * The metric is ONE enumerated field rather than a category and a stat,
     * and that is the interceptions guard expressed where it cannot be
     * forgotten: there is no way to emit a valid category with a stat that
     * does not belong to it, because the pair is a single value.
     */
    public function schema(JsonSchema $schema): array
    {
        $metrics = array_values(array_unique([
            ...array_keys(StatCatalog::answerable()),
            ...array_keys(StatCatalog::answerable(team: true)),
        ]));

        return [
            'answerable' => $schema->boolean()->required(),

            'subject' => $schema->string()
                ->enum(['player', 'team', 'leaders'])
                ->description('Who or what the number is about.'),

            'name' => $schema->string()
                ->description('The player or team exactly as the reader wrote it. Empty for leaders.'),

            'metric' => $schema->string()
                ->enum($metrics)
                ->description('One category.stat key from the vocabulary.'),

            'timeframe' => $schema->string()
                ->enum(['season', 'last_game'])
                ->description('last_game is only valid for a single player.'),

            'season_year' => $schema->integer()
                ->nullable()
                ->description('Only when the reader named a literal year.'),

            'note' => $schema->string()
                ->description('Why this cannot be answered, when it cannot.'),
        ];
    }
}
