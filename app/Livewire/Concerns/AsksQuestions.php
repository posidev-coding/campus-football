<?php

namespace App\Livewire\Concerns;

use App\Actions\RecordActivity;
use App\Enums\ActivityKind;
use App\Support\AskExamples;
use App\Support\Search;
use App\Support\StatAnswer;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;

/**
 * The stat-answer half of a search surface, shared by /search and Home's panel.
 *
 * Both surfaces already duplicate their six result lookups, and that is fine —
 * they are one line each and differ by limit. This is not: it is the gate, the
 * spend and the staleness rule, and two copies of it would drift the first time
 * one was fixed.
 *
 * THE ANSWER IS PINNED TO THE QUESTION IT ANSWERS. `asked` holds the exact text
 * that produced `answer`, and everything reads through {@see stale()} — so a
 * keystroke, a `clear()`, a back button or a URL change all retire the answer
 * without anything having to remember to. An `updated` hook would have covered
 * only the first of those, and the failure would be an old number sitting under
 * a new question, which is indistinguishable from a wrong answer.
 */
trait AsksQuestions
{
    /**
     * All three are server-set and Locked, and they are locked TOGETHER: the
     * state a decline renders is a match with no default arm, so an `answer`
     * a browser could null out while the kind beside it still said "resolved"
     * would be a crash rather than a wrong sentence. The answer also carries
     * the `href` this screen links to, which is not somebody else's to write.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $answer = null;

    /** The exact question `answer` belongs to. Null before anything is asked. */
    #[Locked]
    public ?string $asked = null;

    /**
     * WHY the last ask came back without a number — one of StatAnswer's
     * decline constants, and the only part of a decline that crosses to the
     * client.
     *
     * The REASON beside it never does. It is developer prose naming metrics,
     * spend limits and resolved player names, it belongs in a log, and a
     * public Livewire property is a round trip through the browser. Locked
     * because this decides what a reader is told, and nothing typed in a
     * browser gets to choose that.
     */
    #[Locked]
    public string $decline = StatAnswer::RESOLVED;

    /**
     * Spend a question. A public Livewire action, so it is a public endpoint —
     * every gate lives in StatAnswer rather than in the button that calls it.
     */
    public function ask(): void
    {
        $this->asked = trim($this->q);

        /*
         * All three slots. The reason used to be dropped on the floor here,
         * and with it every way of telling a spend-limit outage from "we hold
         * no rushing yards for him" — twelve distinct causes arriving at one
         * sentence on screen and nothing at all in the log (CFB-24).
         */
        [$this->answer, $reason, $this->decline] = app(StatAnswer::class)->for($this->q, auth()->user());

        if ($this->answer === null) {
            $this->logDecline($reason);
        }

        /*
         * Counted AFTER the answer resolves, and the facet is WHY — a
         * question that came back with a number and one that came back
         * against a spend limit are different events, and a single
         * `stat_asked` total that could not tell them apart would read as
         * healthy demand on a week the feature was down.
         *
         * `$this->decline` is StatAnswer::RESOLVED on the answered path, so
         * the facet is never invented and never null.
         */
        app(RecordActivity::class)->action(ActivityKind::StatAsked, request(), $this->decline);
    }

    /**
     * The decline, in the log, with no reader in it.
     *
     * ONE message and one pair of keys, matching StatAnswer::intent()'s own
     * line, so a single grep answers "why did asks stop working" — the whole
     * point of carrying the reason at all. The level splits on whose problem
     * it is: a question we cannot answer is ordinary business and a call we
     * could not make is not.
     *
     * DELIBERATELY AGGREGATE. No question text and no user id: this layer's
     * telemetry carries neither, and a log of what people asked is a log
     * somebody has to be trusted with.
     */
    private function logDecline(string $reason): void
    {
        $context = ['failure' => $this->decline, 'detail' => $reason];

        $this->decline === StatAnswer::DECLINE_UNAVAILABLE
            ? Log::warning('Stat question not answered.', $context)
            : Log::info('Stat question not answered.', $context);
    }

    /**
     * Which of the seven things this surface should show.
     *
     * `idle` is the one that makes the feature discoverable at all. Nobody
     * types a question into a search box unless something told them they
     * could, so the empty screen — the first thing every reader sees — carries
     * example questions built from their own team. Everything else here is
     * about not getting in the way once they are actually searching.
     */
    public function askState(): string
    {
        $user = auth()->user();

        // Nothing about asking is ever shown to somebody who cannot ask.
        if (! StatAnswer::available($user)) {
            return 'none';
        }

        if ($this->asked !== null && ! $this->stale()) {
            if ($this->answer !== null) {
                return 'answered';
            }

            /*
             * A miss is not one state. "We hold no rushing yards for him in
             * 2026" is a true answer and should read like one; a refused
             * budget or a classifier that never came back is us being broken,
             * and dressing that up as a fact about our data is what made the
             * feature look like it simply did not work.
             *
             * A cap reached mid-ask lands on the state the screen already has
             * for it, rather than on an apology — the reader is not missing an
             * answer, they are out of questions until tomorrow.
             *
             * No default arm on purpose: for() never pairs a null answer with
             * RESOLVED, and inventing a fallback here is exactly the
             * substitution that hid all twelve causes behind one sentence.
             */
            return match ($this->decline) {
                StatAnswer::DECLINE_CAPPED => 'capped',
                StatAnswer::DECLINE_UNAVAILABLE => 'unavailable',
                StatAnswer::DECLINE_DATA => 'missed',
            };
        }

        if (Search::tooShort($this->q)) {
            return StatAnswer::capped($user) ? 'none' : 'idle';
        }

        if (! StatAnswer::looksLikeAQuestion($this->q)) {
            return 'none';
        }

        /*
         * The additive rule, sharpened. It was "never offer while there are
         * results", which also silenced the most natural question anybody
         * types — "Mensah passing yards?" matches a player AND wants a number.
         * So a query that OUTRIGHT asks is offered an answer above the rows;
         * one that merely runs long stands down, because five words is also
         * what a fixture name looks like. Neither ever displaces a result.
         */
        if (! StatAnswer::asksOutright($this->q) && $this->searchFoundSomething()) {
            return 'none';
        }

        return StatAnswer::capped($user) ? 'capped' : 'offer';
    }

    /**
     * What the input invites.
     *
     * It names questions only where they can actually be asked. A guest —
     * or anybody with the flag closed — is promised nothing the screen cannot
     * then do, which is the same rule the example pad follows.
     */
    public function searchPlaceholder(): string
    {
        return StatAnswer::available(auth()->user())
            ? 'Teams, players, or ask a question…'
            : 'Teams, players, coaches, games…';
    }

    /**
     * Questions worth tapping, for the idle screen. Empty for anyone who
     * cannot ask, so the view needs no second check.
     *
     * @return list<string>
     */
    public function askExamples(): array
    {
        return AskExamples::for(auth()->user());
    }

    /**
     * Tap an example: it becomes the query, and it is asked.
     *
     * The query moves too rather than the answer arriving out of nowhere —
     * the reader has to be able to see what was asked, edit it, and ask
     * again. A shareable /search URL falls out of that for free.
     */
    public function askExample(int $index): void
    {
        // An INDEX, never the text. A Livewire action is a public endpoint, so
        // taking the question as a string would let anything be posted to it;
        // this way the button can only ever ask one of the three we built.
        $question = $this->askExamples()[$index] ?? null;

        if ($question === null) {
            return;
        }

        $this->q = $question;

        $this->ask();
    }

    /** The answer, but only while the question it answers is still on screen. */
    public function resolvedAnswer(): ?array
    {
        return $this->stale() ? null : $this->answer;
    }

    private function stale(): bool
    {
        return $this->asked !== trim($this->q);
    }

    /** Did the ordinary search find anything at all? */
    abstract protected function searchFoundSomething(): bool;
}
