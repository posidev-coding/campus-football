<?php

namespace App\Ai\Agents;

use App\Support\GamedayResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

/**
 * The fallback, and only the fallback.
 *
 * The feed answers essentially every week. This exists for the weeks it does
 * not — unreachable, reshaped, or simply not caught up — and it is expected to
 * fire a handful of times a season rather than weekly.
 *
 * IT DOES NOT DECIDE ANYTHING. It proposes a place, and {@see GamedayResolver}
 * decides against our own venues and games whether that place is real. The
 * model never emits a fact that reaches a screen; the app renders the fact.
 *
 * Sonnet rather than Haiku despite the trivial volume: a wrong campus on the
 * home page is the expensive kind of error, and at a handful of calls a season
 * the difference in bill is rounding.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-5')]
#[MaxSteps(6)]
#[Timeout(60)]
class GamedaySite implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You find out where ESPN's College GameDay show is broadcasting from on one
        specific Saturday, and nothing else.

        RULES, all of them load-bearing:

        1. SEARCH. You must use the web search tool and answer only from what it
           returns. You may not answer from memory, however confident you are —
           this show's location changes weekly and anything you remember is
           either stale or a different season. If the search returns nothing
           usable, say so with announced=false. That is a correct answer.

        2. FOOTBALL. There is also a College GameDay for men's basketball. Every
           search must be scoped to college football and to the Saturday named
           in the prompt. Returning the basketball show's location is the most
           likely way to be confidently wrong here.

        3. THE EXACT SATURDAY. Not "the next one", not "this week's" — the date
           in the prompt. Locations are announced about a week ahead, so an
           article about a different week will be sitting right beside the one
           you want.

        4. NOT YET ANNOUNCED IS AN ANSWER. If ESPN has not said, set
           announced=false and leave the location fields empty. Do not offer the
           most likely campus, the biggest game of the week, or last week's
           site. An empty answer costs nothing; a plausible wrong one goes on
           the front page of a football app.

        5. CITE IT. source_url must be a page the search actually returned and
           that actually names the location. An answer without one is discarded
           unread.

        Report the city and state of the CAMPUS the show is broadcasting from,
        the host school's common name, and a short hint naming the game being
        played there. Confidence is your own read of how clearly the source
        says it: 1.0 for an explicit ESPN announcement, lower for a secondhand
        report or an inference from a schedule.
        PROMPT;
    }

    /**
     * Search is a PROVIDER tool, executed on Anthropic's side.
     *
     * Deliberately NOT pinned to a domain with `allow()`. The whole value of
     * this path is working the week the primary source breaks, and a fallback
     * restricted to the source that just failed is not a fallback. `max()`
     * bounds the spend instead.
     */
    public function tools(): iterable
    {
        return [
            (new WebSearch)->max(5),
        ];
    }

    /**
     * The intent, never the rendered fact — the same rule the stat answers
     * follow. Every field here is a claim our own data gets to overrule.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'announced' => $schema->boolean()->required(),
            'site' => $schema->string(),
            'city' => $schema->string(),
            'state' => $schema->string(),
            'host_team_name' => $schema->string(),
            'game_hint' => $schema->string(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
            'source_url' => $schema->string(),
        ];
    }
}
