<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress for `cfb:migrate`.
 *
 * A full six-season migration is thousands of requests and runs for hours, so
 * it has to be resumable: an interrupted run must restart at the first
 * incomplete step rather than from zero. This table is the record of what
 * finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('step', 40);
            $table->unsignedSmallInteger('season_year')->nullable();
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('records')->default(0);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // One row per step per season, so a re-run updates rather than
            // appending a second history of the same work.
            $table->unique(['step', 'season_year']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
