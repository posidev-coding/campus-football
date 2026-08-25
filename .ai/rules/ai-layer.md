---
paths:
  - 'app/Ai/**,app/Support/Recap*.php,app/Support/AiFailure.php,app/Support/GamedayFallback.php'
---

# The AI layer

## Generated copy is swept, null means the deterministic copy, and the two spend walls look nothing alike
Nothing a model writes reaches a reader unswept. `RecapSweep` errs toward REJECTION on purpose: a false positive costs one reader one week of generated copy; a false negative is a joke about somebody in their inbox with our name on it.

Every failure path returns NULL — flag closed, budget spent, nothing played, API down, copy that failed the sweep — and null renders the deterministic `Voice` copy that shipped first. Null is the default state, never an error state; a generated surface must read as finished when nothing generated it.

Strong profanity is banned at EVERY register, R included (PG additionally loses the mild words its own description already rules out). A product line, not an oversight: every `r` line in Voice is clean, so generated copy that swore would be louder than anything a human wrote, and the App Store age rating follows the loudest thing in the build. Registers differ in ATTITUDE, not vocabulary — do not "fix" this as a bug.

Mirror `config('cfb.ai_recaps')`, never `Feature::active()`, in anything per-user: Pennant's database driver persists a row per resolve, so a newsletter fan-out would write one per subscriber and then answer from stale rows.

`AiFailure` exists because the two ways to run out of money look nothing alike: OUR Console limit is a 400 (`"specified API usage limits"`) that falls through every handler in laravel/ai and arrives as a bare RequestException; the TIER cap is a 429 whose `error.details.error_code = enforced_spend_limit_reached` sits ONE LINK DOWN the chain, because the SDK rewraps it as RateLimitedException — so it reads as ordinary rate limiting, which is a thing you wait out. It is not. Walk `getPrevious()`.
