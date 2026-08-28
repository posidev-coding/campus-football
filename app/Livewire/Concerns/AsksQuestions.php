<?php

namespace App\Livewire\Concerns;

use App\Support\AskExamples;
use App\Support\Search;
use App\Support\StatAnswer;

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
    /** @var array<string, mixed>|null */
    public ?array $answer = null;

    /** The exact question `answer` belongs to. Null before anything is asked. */
    public ?string $asked = null;

    /**
     * Spend a question. A public Livewire action, so it is a public endpoint —
     * every gate lives in StatAnswer rather than in the button that calls it.
     */
    public function ask(): void
    {
        $this->asked = trim($this->q);

        [$this->answer] = app(StatAnswer::class)->for($this->q, auth()->user());
    }

    /**
     * Which of the six things this surface should show.
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
            return $this->answer === null ? 'missed' : 'answered';
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
