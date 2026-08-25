<?php

namespace App\Support;

use App\Models\AiSpend;

/**
 * The monthly ceiling — one authority, asked from both the request path and
 * the queue.
 *
 * The house pattern for the third time: `mail_daily_budget`, `sms_daily_budget`
 * and `ESPN_RATE_LIMIT` all say THE BUDGET IS OURS, NOT THEIRS. The Console's
 * own spend limit is the outer wall and it fails as an HTTP error mid-request;
 * this is the one that can decline a call before it is made, while there is
 * still a deterministic fallback to serve instead.
 *
 * Two switches, deliberately separate:
 *
 *   `cfb.ai_enabled`         is AI on at all — the master, off by default
 *   `cfb.ai_monthly_budget`  what it may spend before it stops
 *
 * A budget of zero or less means UNCAPPED, matching `mail_daily_budget`'s
 * convention exactly. That is a real setting, not an oversight: the Console
 * limit still applies, and somebody deliberately running without a local
 * ceiling should not have to fight one.
 *
 * DELIBERATELY NOT A PENNANT FLAG. Resolving Pennant persists a row per scope
 * on the database driver, so a flag that flipped with the budget would strand
 * stale rows the moment spend crossed the line and answer from them afterwards.
 * The flags say whether a FEATURE exists; this says whether there is money.
 * Two questions, two answers.
 */
class AiBudget
{
    /**
     * Is the AI layer switched on at all?
     *
     * Reads config rather than `env()` so flipping it is an environment change
     * with an instant rollback, and so a test can set it without touching the
     * environment.
     */
    public function enabled(): bool
    {
        return config('cfb.ai_enabled') === true;
    }

    /** The month-to-date total, in dollars. */
    public function spent(): float
    {
        return (float) AiSpend::query()->thisMonth()->sum('cost');
    }

    /** The ceiling, in dollars. Zero or less means uncapped. */
    public function budget(): float
    {
        return (float) config('cfb.ai_monthly_budget');
    }

    /** What is left, or null when there is no ceiling to be left of. */
    public function remaining(): ?float
    {
        $budget = $this->budget();

        return $budget > 0 ? max(0.0, round($budget - $this->spent(), 6)) : null;
    }

    /** Month-to-date spend as a fraction of the ceiling, or null if uncapped. */
    public function fraction(): ?float
    {
        $budget = $this->budget();

        return $budget > 0 ? round($this->spent() / $budget, 4) : null;
    }

    /**
     * May we make a call right now?
     *
     * The one question every caller asks, and it answers BOTH switches at
     * once — a caller that checked only the budget would happily spend money
     * with the feature switched off.
     */
    public function allows(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $budget = $this->budget();

        return $budget <= 0 || $this->spent() < $budget;
    }

    /**
     * Why a call was refused, for a log or an exception. Null when it was not.
     *
     * A developer message, never copy: what a reader sees comes from Voice,
     * because a baked-in string can only speak in one register.
     */
    public function refusal(): ?string
    {
        if (! $this->enabled()) {
            return 'The AI layer is switched off (cfb.ai_enabled).';
        }

        if (! $this->allows()) {
            return sprintf(
                'The monthly AI budget is spent: $%s of $%s since %s.',
                number_format($this->spent(), 2),
                number_format($this->budget(), 2),
                now()->timezone(config('cfb.timezone'))->startOfMonth()->toDateString(),
            );
        }

        return null;
    }
}
