<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Saturday: where College GameDay is broadcasting from.
     *
     * Keyed on `(season_year, saturday)` rather than on a week number, because
     * the feed names a DATE and the resolver matches on one. Unique, so a
     * command that runs five times in a week updates one row instead of
     * stacking five — the same keyed idempotency the wallet entries and the
     * workbook use.
     *
     * Almost everything is nullable on purpose. A row written before ESPN has
     * announced anything is a real row with a real answer: status `unknown`
     * and no site. Nothing here is ever filled with a placeholder.
     */
    public function up(): void
    {
        Schema::create('gameday_weeks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('season_year');
            $table->date('saturday');

            /*
             * The campus or venue as the source named it, kept beside the
             * resolved team rather than replaced by it: when the resolver
             * fails, what the source SAID is the only lead a human has.
             */
            $table->string('site', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 40)->nullable();

            /*
             * NOT foreignId(). Both parents are keyed by ESPN's own ids and
             * are right-sized for them — `teams.id` is mediumint unsigned and
             * `games.id` is int unsigned — while foreignId() emits bigint.
             * MySQL refuses to constrain a wide child against a narrow parent,
             * and it fails on the ALTER after the table is already created, so
             * the migration leaves a table behind and reports itself pending.
             */
            $table->unsignedMediumInteger('team_id')->nullable();
            $table->unsignedInteger('game_id')->nullable();

            $table->string('status', 12)->default('unknown');

            // 0.00-1.00 from the model path. Null on the feed path, which does
            // not guess and so has nothing to be confident about.
            $table->decimal('confidence', 3, 2)->nullable();

            // Wide, and deliberately not narrowed: InnoDB stores a varchar
            // variable-length, and neither column is indexed, sorted or grouped.
            $table->string('source_url', 512)->nullable();

            /*
             * The feed is hand-maintained and has already shipped last
             * season's venue under this season's matchup. Hashing what we
             * parsed makes a SHAPE change detectable instead of silent.
             */
            $table->string('payload_hash', 64)->nullable();

            $table->timestamp('announced_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->foreign('game_id')->references('id')->on('games')->nullOnDelete();
            $table->unique(['season_year', 'saturday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameday_weeks');
    }
};
