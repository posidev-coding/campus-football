<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly loop's three claims — what has already been SAID about a slate,
 * kept apart from what has been PAID for it.
 *
 * `settled_at` claims the money and must never do double duty: a broken
 * announcement has to be repairable by nulling one column and re-running,
 * without the wallet hearing about it. So the noise gets its own stamps.
 *
 * They live on `slates` rather than on `slate_entries` for a reason that is
 * the whole point of the reminder: an entry row is created LAZILY on a
 * member's first pick, so somebody who has picked nothing has no row — and
 * they are exactly who a reminder is for. Stamp the thing being swept, the
 * way `games.kickoff_alert_sent_at` does, never the recipient.
 *
 * Deliberately NO index. `slates` is bounded by contests × Saturdays — a few
 * hundred rows across a whole season, smaller than one week of `games` — so
 * the sweep's `status = published AND stamp IS NULL` scan is free. Materialize
 * only behind a measurement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slates', function (Blueprint $table) {
            // Wave 1: the day-before nudge, anchored on first kickoff.
            $table->timestamp('picks_reminded_at')->nullable()->after('published_at');

            // Wave 2: the 90-minute last call.
            $table->timestamp('last_call_sent_at')->nullable()->after('picks_reminded_at');

            // The results announcement — a SEPARATE claim from settled_at.
            $table->timestamp('results_announced_at')->nullable()->after('settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('slates', function (Blueprint $table) {
            $table->dropColumn(['picks_reminded_at', 'last_call_sent_at', 'results_announced_at']);
        });
    }
};
