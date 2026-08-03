<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conference standings, stored — not derived at request time.
 *
 * v3 had no standings table at all. It scraped standings out of the `standings`
 * sub-object of an arbitrarily chosen game's summary payload, picked that game
 * with `latest()` (which sorts by created_at, not kickoff), and defaulted every
 * field to zero — so a lookup miss silently overwrote a 9-1 team with 0-0.
 *
 * Two sources are stored side by side for the same team-season:
 *   `espn`     — the authoritative feed, read from the group standings endpoint
 *   `computed` — derived independently from our own completed games
 *
 * They are never merged. Storing both is what makes a silent feed regression
 * visible: the reconciler compares them and sets `diverged_at`, which surfaces
 * as an alert in the admin panel rather than as wrong records on a public page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('season_year');
            $table->unsignedSmallInteger('conference_id')->nullable();
            $table->unsignedMediumInteger('team_id');
            $table->string('source', 10);

            $table->unsignedTinyInteger('overall_wins')->default(0);
            $table->unsignedTinyInteger('overall_losses')->default(0);
            $table->unsignedTinyInteger('overall_ties')->default(0);

            $table->unsignedTinyInteger('conf_wins')->default(0);
            $table->unsignedTinyInteger('conf_losses')->default(0);
            $table->unsignedTinyInteger('conf_ties')->default(0);

            // Kept as ESPN's display strings; these are for reading, not maths.
            $table->string('home_record', 20)->nullable();
            $table->string('away_record', 20)->nullable();
            $table->string('vs_ranked_record', 20)->nullable();
            $table->string('streak', 10)->nullable();

            $table->decimal('win_pct', 5, 4)->nullable();
            $table->decimal('conf_win_pct', 5, 4)->nullable();
            $table->unsignedSmallInteger('points_for')->default(0);
            $table->unsignedSmallInteger('points_against')->default(0);
            $table->smallInteger('point_differential')->default(0);
            $table->unsignedTinyInteger('playoff_seed')->nullable();
            $table->decimal('games_behind', 4, 1)->nullable();

            // Set by the reconciler when espn and computed disagree.
            $table->timestamp('diverged_at')->nullable();
            $table->json('divergence')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('conference_id')->references('id')->on('conferences')->nullOnDelete();

            $table->unique(['season_year', 'conference_id', 'team_id', 'source'], 'standings_unique_entry');
            $table->index(['season_year', 'conference_id', 'source']);
            $table->index('diverged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standings');
    }
};
