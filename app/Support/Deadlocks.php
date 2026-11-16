<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * One bounded retry for a write that can lose a lock cycle, and nothing else.
 *
 * The article pivots are written by jobs that run in parallel on purpose —
 * `SyncNews` for `article_team`, `FetchGameSummary` for `article_game` — and
 * ESPN repeats a national story across many games' related lists, so two
 * workers routinely take the same unique-index locks at the same instant and
 * MySQL rolls one of them back. The loser has already spent its ESPN request;
 * trying the idempotent write again is the whole answer.
 *
 * It lives here, once, because it was COPIED once: `article_game` borrowed the
 * `article_team` write's shape and left the retry behind. A third pivot calls
 * this rather than growing a third loop.
 *
 * Never inside a transaction. MySQL rolls back the WHOLE transaction on 1213,
 * so retrying one statement inside it would replay half a unit of work against
 * nothing — wrap the transaction, or call this outside it.
 */
class Deadlocks
{
    /**
     * Extra attempts after a deadlock, and only after a deadlock.
     *
     * Two, because the cycle needs another writer holding the same rows' locks
     * at the same instant and that window is milliseconds wide — a third
     * attempt would be waiting on something other than contention, and the
     * honest answer to that is to fail and leave a ledger row.
     */
    public const RETRIES = 2;

    /**
     * Run the write, re-running it after a deadlock up to RETRIES times.
     *
     * The write must be safe to repeat (insertOrIgnore, a conditional UPDATE),
     * and anything that fixes lock ORDER must happen before the call, so a
     * second attempt cannot take the locks in a third order.
     */
    public static function retry(callable $write): void
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $write();

                return;
            } catch (QueryException $e) {
                if ($attempt >= self::RETRIES || ! self::isDeadlock($e)) {
                    throw $e;
                }

                // Jittered, so two writers that just deadlocked with each
                // other do not wake together and do it again.
                usleep(random_int(20_000, 80_000));
            }
        }
    }

    /**
     * A deadlock and nothing else.
     *
     * SQLSTATE 40001 is "serialization failure", which for MySQL is error
     * 1213 — the loser of a lock cycle, already rolled back and safe to try
     * again. Every other QueryException is re-raised untouched: a retry loop
     * that swallowed a missing column or a foreign key would turn a bug into
     * three seconds of silence.
     */
    public static function isDeadlock(QueryException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode()) === '40001';
    }
}
