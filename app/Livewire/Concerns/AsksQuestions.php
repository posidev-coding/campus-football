<?php

namespace App\Livewire\Concerns;

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
     * Which of the five things this surface should show.
     *
     * `none` most of the time: the offer appears only where ordinary search
     * came back empty, which is what keeps the feature strictly additive — it
     * can never stand in front of a result somebody was about to tap.
     */
    public function askState(): string
    {
        if ($this->asked !== null && ! $this->stale()) {
            return $this->answer === null ? 'missed' : 'answered';
        }

        if ($this->searchFoundSomething() || ! StatAnswer::askable($this->q, auth()->user())) {
            return 'none';
        }

        return StatAnswer::capped(auth()->user()) ? 'capped' : 'offer';
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
