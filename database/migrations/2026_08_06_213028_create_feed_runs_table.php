<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per invocation of a recurring sync command — the ledger the admin
 * panel's Sync Health page reads.
 *
 * `sync_runs` is NOT this: that table is cfb:migrate's private resume ledger,
 * unique per (step, season) and overwritten on re-run, which is exactly right
 * for resuming a backfill and useless for "did last night's standings pass
 * actually run, and what did it cost". Every scheduled command's outcome was
 * previously console text that vanished with the process — a failing sync
 * looked identical to a healthy one from the database's point of view, which
 * is how a silent 403 cost a full day of games.
 *
 * Rows are pruned after a fortnight; the value is operational, not archival.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_runs', function (Blueprint $table) {
            $table->id();
            // `games:live`, `sync:standings`, `summaries:missing` — the
            // command family and its variant, not the raw argv.
            $table->string('command', 60);
            $table->unsignedSmallInteger('season_year')->nullable();
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('records')->default(0);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            // Fan-out commands queue a batch rather than doing the work
            // inline; carrying the id lets the dashboard link the run to its
            // job_batches progress row.
            $table->string('batch_id', 40)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // "Latest run of this command" is the dashboard's whole query.
            $table->index(['command', 'started_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_runs');
    }
};
