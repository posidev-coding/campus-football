---
paths:
  - 'app/Ai/**,app/Support/Recap*.php,app/Support/AiFailure.php,app/Support/GamedayFallback.php,app/Support/StatAnswer.php,app/Support/Stats/StatCatalog.php,app/Livewire/Concerns/AsksQuestions.php'
---

# The AI layer

## Generated copy is swept, null means the deterministic copy, and the two spend walls look nothing alike
Nothing a model writes reaches a reader unswept. `RecapSweep` errs toward REJECTION on purpose: a false positive costs one reader one week of generated copy; a false negative is a joke about somebody in their inbox with our name on it.

Every failure path returns NULL — flag closed, budget spent, nothing played, API down, copy that failed the sweep — and null renders the deterministic `Voice` copy that shipped first. Null is the default state, never an error state; a generated surface must read as finished when nothing generated it.

Strong profanity is banned at EVERY register, R included (PG additionally loses the mild words its own description already rules out). A product line, not an oversight: every `r` line in Voice is clean, so generated copy that swore would be louder than anything a human wrote, and the App Store age rating follows the loudest thing in the build. Registers differ in ATTITUDE, not vocabulary — do not "fix" this as a bug.

Mirror `config('cfb.ai_recaps')`, never `Feature::active()`, in anything per-user: Pennant's database driver persists a row per resolve, so a newsletter fan-out would write one per subscriber and then answer from stale rows.

`AiFailure` exists because the two ways to run out of money look nothing alike: OUR Console limit is a 400 (`"specified API usage limits"`) that falls through every handler in laravel/ai and arrives as a bare RequestException; the TIER cap is a 429 whose `error.details.error_code = enforced_spend_limit_reached` sits ONE LINK DOWN the chain, because the SDK rewraps it as RateLimitedException — so it reads as ordinary rate limiting, which is a thing you wait out. It is not. Walk `getPrevious()`.

## The stat answer is additive-only, the metric is one enumerated pair, and the answer is pinned to its question
The offer appears ONLY where ordinary search found nothing. That is what makes it strictly additive — it can never stand in front of a result somebody was about to tap — and it is why declining is the cheap default: a decline puts the reader exactly where they were.

`metric` is a SINGLE enumerated field, `category.stat`, not two fields. `interceptions` is picks caught in one category and thrown in another, and player/team stats share names outright, so a vocabulary keyed on the stat alone answers from whichever row comes back first. The vocabulary is generated from `StatCatalog::answerable()` (the leaderboards), so every answerable stat is one the reader can verify on a screen, and prompt and resolver cannot drift.

The answer is pinned to the question that produced it (`asked`), and everything reads through a staleness check. A keystroke, `clear()`, the back button and a URL change all retire it; an `updated` hook covers only the first, and the failure is an old number under a new question — indistinguishable from a wrong answer.

The ask is an EXPLICIT TAP, never the debounce. Both search surfaces are `wire:model.live.debounce.300ms`, so an automatic ask classifies every intermediate substring — three calls to reach one question, and a bill that scales with typing speed.

Gates run cheapest-first: signed in → flag config → looks like a question → daily cap → budget. Guests get today's Search unchanged: reading is never gated here, but an answer is a computation with a bill that an anonymous session cannot be capped against. The intent (not the answer) caches 24h behind a normalized hash, and the RateLimiter is hit inside the cache-miss branch so the cap counts CALLS, not taps.

Year resolution is `CfbCalendar::resultsYear()`, never `currentYear()` — in August they differ and the current one has no games in it, so every answer would be "we hold nothing". A test for this passes for the wrong reason unless the fixture holds a season that is SCHEDULED but unplayed.
