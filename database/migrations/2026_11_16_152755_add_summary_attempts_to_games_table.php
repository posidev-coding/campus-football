<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of asking ESPN for a box score and getting nothing back.
 *
 * A `GameSummary` row is only written when ESPN answers, so a completed game it
 * never answers used to match `cfb:summaries --missing` on every run, forever,
 * at one 544 KB request a time. The attempt lives HERE, on the game, rather
 * than as an empty summary row: a placeholder summary would tell every screen
 * that reads one that a box score exists.
 *
 * `summary_attempted_at` is null until a fetch comes back empty; the count is
 * what the retry backs off on. Neither is touched by a fetch that succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->timestamp('summary_attempted_at')->nullable()->after('kickoff_alert_sent_at');
            $table->unsignedSmallInteger('summary_attempts')->default(0)->after('summary_attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['summary_attempted_at', 'summary_attempts']);
        });
    }
};
