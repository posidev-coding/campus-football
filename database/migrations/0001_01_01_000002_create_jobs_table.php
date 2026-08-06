<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The queue tables Redis does NOT replace.
     *
     * `QUEUE_CONNECTION=redis`, so the `jobs` table is gone — Redis holds the
     * pending work — and `CACHE_STORE=redis` removed `cache`/`cache_locks`
     * with it (that migration no longer exists).
     *
     * These two stay, and the reason is easy to get wrong: batching and
     * failed-job logging are configured SEPARATELY from the queue connection
     * and both default to the database.
     *
     *   queue.batching  -> `job_batches`, and `cfb:summaries` dispatches a
     *                      real Bus::batch, so dropping this breaks the
     *                      backfill rather than just losing bookkeeping
     *   queue.failed    -> `failed_jobs`, driver `database-uuids`. A redis
     *                      queue that loses a job silently is not something
     *                      to opt into; this is also what Laravel Cloud's
     *                      failed-jobs view reads
     *
     * The filename still says "jobs" because renaming a migration that has
     * already run makes Laravel treat it as a NEW one and re-run it against
     * tables that already exist.
     */
    public function up(): void
    {
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
