<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A job whose whole purpose is a model call ran with no money to make it.
 *
 * Deliberately LOUD. Everywhere the AI is one optional step of something else
 * — the recap inside the newsletter, the answer beside normal search results —
 * an exhausted budget is a quiet fall back to deterministic content, because
 * the reader still gets the real thing. A job that exists ONLY to call the
 * model has nothing to fall back to, so it fails, lands in the `feed_runs`
 * ledger through the Queue::failing hook, and shows on Sync Health.
 *
 * Hitting a monthly ceiling should never be routine. It is a retry storm or a
 * runaway loop, and the failure mode to avoid is not noise — it is silence.
 *
 * Carries a developer message only. What a reader sees comes from Voice.
 */
class AiBudgetExceeded extends RuntimeException {}
