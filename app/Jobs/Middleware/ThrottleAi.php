<?php

namespace App\Jobs\Middleware;

use App\Exceptions\AiBudgetExceeded;
use App\Support\AiBudget;
use Closure;

/**
 * Keep a model call inside the monthly budget — the fourth of these, beside
 * ThrottleEspn, ThrottleMail and ThrottleSms.
 *
 * IT FAILS RATHER THAN RELEASING, and that is the one place it departs from
 * its siblings. Mail releases because the window is a day and tomorrow is a
 * fine time to send a newsletter. This window is a MONTH: releasing would park
 * a job for up to thirty-one days, past any sane `retry_until`, and the
 * "recovery" would be a job that silently expired.
 *
 * ONLY FOR JOBS THAT ARE NOTHING BUT A MODEL CALL. Where the AI is one
 * optional step of something else — the recap inside the newsletter — this
 * middleware is the wrong tool, because failing the job would take the
 * deterministic newsletter down with it. Those callers ask
 * {@see AiBudget::allows()} at the call site and fall back to real content.
 */
class ThrottleAi
{
    public function handle(object $job, Closure $next): mixed
    {
        $budget = app(AiBudget::class);

        if (! $budget->allows()) {
            throw new AiBudgetExceeded($budget->refusal() ?? 'The AI layer refused this call.');
        }

        /*
         * Nothing is recorded here, unlike ThrottleMail which hits its limiter
         * BEFORE the send. There is nothing to record yet: what a call costs
         * is known only once the API says how many tokens it used, so the
         * ledger write belongs at the call site, through RecordAiSpend.
         *
         * The consequence is worth stating plainly: this is a CHECK, not a
         * reservation. Several jobs running in parallel can each pass it and
         * push the month a little past the ceiling together. That is the right
         * trade at these numbers — a projected $9 against a $25 ceiling — and
         * the Console's own spend limit is the wall that does not overshoot.
         */
        return $next($job);
    }
}
