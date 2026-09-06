<?php

namespace App\Livewire\Concerns;

use App\Actions\RecordActivity;
use App\Enums\ActivityKind;
use App\Support\Search;
use Livewire\Attributes\Locked;

/**
 * Count a search — once, when the query first becomes a real one.
 *
 * ONE FILE for all three surfaces (Home's panel, /search, the ⌘K palette),
 * because `ActivityKind::Searched` has to mean the same thing on each of them:
 * a signal emitted from three places with three slightly different guards
 * stops measuring what it is named after, which is the lesson
 * `onboarding_registered` already paid for.
 *
 * NOT PER KEYSTROKE. All three inputs are `wire:model.live.debounce`, so a
 * counter on the `updated` hook alone would count typing speed — "search" would
 * read forty on a fast phone and eight on a slow one for the same question.
 * The boundary is the query crossing `Search::MIN_LENGTH`, which is also the
 * point the app itself starts looking anything up.
 *
 * The flag is a LOCKED PUBLIC property, deliberately: a private one is not
 * hydrated, so every debounce round trip would arrive with a fresh instance
 * and a fresh false, and the once-per-session guard would count every tick
 * after all. Locked because nothing typed in a browser gets to reopen it.
 */
trait RecordsSearches
{
    /** Has this component instance already counted its search? */
    #[Locked]
    public bool $searchCounted = false;

    public function updatedQ(): void
    {
        if ($this->searchCounted || Search::tooShort($this->q)) {
            return;
        }

        $this->searchCounted = true;

        app(RecordActivity::class)->action(ActivityKind::Searched, request());
    }
}
