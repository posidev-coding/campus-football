<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            // ESPN event id.
            $table->unsignedInteger('id')->primary();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('week_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('venue_id')->nullable();

            $table->timestamp('kickoff_at');
            /*
             * Day of week in America/New_York, stored rather than derived.
             *
             * Contests may only slate Saturday games, and deriving the ET day
             * from a UTC timestamp in SQL is both slow and wrong across the
             * EDT/EST boundary that every CFB season straddles. Written once at
             * sync time by the app, in the correct zone.
             *
             * NOT indexed, deliberately. Seven distinct values over 5,793 games
             * and 83% of them 'Sat' — and the only query, `slateEligible()`,
             * asks for exactly that 83%. An index returning most of the table
             * is one the optimizer correctly refuses to use; measured, it took
             * zero reads across every screen in the app.
             */
            $table->string('kickoff_day', 3);

            // "Alabama at Georgia" — 65 at the longest across 5,793 games.
            $table->string('name', 120);
            $table->string('short_name', 40)->nullable();
            $table->boolean('neutral_site')->default(false);
            $table->boolean('conference_game')->default(false);

            // Denormalized home/away. The scoreboard is the hottest query in
            // the app and this keeps it join-free.
            $table->unsignedMediumInteger('home_team_id')->nullable();
            $table->unsignedTinyInteger('home_score')->default(0);
            $table->unsignedTinyInteger('home_rank')->nullable();
            $table->string('home_record', 20)->nullable();
            $table->json('home_line_scores')->nullable();
            $table->decimal('home_win_prob', 5, 2)->nullable();

            $table->unsignedMediumInteger('away_team_id')->nullable();
            $table->unsignedTinyInteger('away_score')->default(0);
            $table->unsignedTinyInteger('away_rank')->nullable();
            $table->string('away_record', 20)->nullable();
            $table->json('away_line_scores')->nullable();
            $table->decimal('away_win_prob', 5, 2)->nullable();

            $table->string('status', 30)->nullable();
            $table->string('status_detail', 60)->nullable();
            $table->unsignedTinyInteger('period')->default(0);
            $table->string('clock', 10)->nullable();
            $table->boolean('completed')->default(false);
            $table->unsignedMediumInteger('attendance')->nullable();
            $table->json('broadcasts')->nullable();

            $table->timestamps();

            $table->foreign('venue_id')->references('id')->on('venues')->nullOnDelete();
            $table->foreign('home_team_id')->references('id')->on('teams')->nullOnDelete();
            $table->foreign('away_team_id')->references('id')->on('teams')->nullOnDelete();

            $table->index(['season_id', 'kickoff_at']);
            // The scoreboard's own index: filters the week, and its second
            // column satisfies the ORDER BY so the slate needs no filesort.
            $table->index(['week_id', 'kickoff_at']);
            $table->index(['completed', 'kickoff_at']);

            /*
             * There is deliberately no (week_id, kickoff_day) index. It read
             * as "Saturday games in a week", but `kickoff_day` is 83% 'Sat',
             * so the second column excludes almost nothing that the first has
             * not already narrowed — and (week_id, kickoff_at) above serves
             * every week_id query anyway. Measured: zero reads.
             *
             * Both dropped indexes cost writes on the hottest table in the
             * app, which the live tier rewrites every minute all Saturday.
             */
        });

        /*
         * Odds as a time series rather than a single current value.
         *
         * ESPN exposes `open`, `current`, and `close` blocks per provider
         * (verified). The delta between open and current is the closest public
         * proxy for where betting money is going — no public API publishes
         * handle or volume — and it is a direct input to the Game Quality Score
         * that drives contest tiering.
         */
        Schema::create('game_odds', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('game_id');
            $table->unsignedSmallInteger('provider_id')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('phase', 10);
            $table->decimal('spread', 5, 1)->nullable();
            $table->decimal('over_under', 5, 1)->nullable();
            $table->integer('moneyline_home')->nullable();
            $table->integer('moneyline_away')->nullable();
            $table->unsignedMediumInteger('favorite_team_id')->nullable();
            $table->string('details', 40)->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->foreign('favorite_team_id')->references('id')->on('teams')->nullOnDelete();
            $table->unique(['game_id', 'provider_id', 'phase']);
            $table->index(['game_id', 'phase']);
        });

        /*
         * ESPN's own matchup quality metrics. Verified live: a sample event
         * returned gameQuality 70.8 and matchupQuality 52.1. These are the
         * highest-weighted signals in the Game Quality Score.
         */
        Schema::create('game_predictors', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('game_id')->unique();
            $table->decimal('game_quality', 5, 2)->nullable();
            $table->decimal('matchup_quality', 5, 2)->nullable();
            $table->decimal('home_projection', 5, 2)->nullable();
            $table->decimal('away_projection', 5, 2)->nullable();
            $table->decimal('home_opp_strength', 6, 2)->nullable();
            $table->decimal('away_opp_strength', 6, 2)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
        });

        Schema::create('rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('week_id')->nullable()->constrained()->nullOnDelete();
            $table->string('poll', 30);
            $table->unsignedMediumInteger('team_id');
            $table->unsignedTinyInteger('rank');
            $table->unsignedTinyInteger('previous_rank')->nullable();
            $table->unsignedMediumInteger('points')->default(0);
            $table->unsignedSmallInteger('first_place_votes')->default(0);
            $table->string('record', 20)->nullable();
            $table->string('trend', 10)->nullable();
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->unique(['season_id', 'week_id', 'poll', 'team_id'], 'rankings_unique_entry');
            $table->index(['poll', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rankings');
        Schema::dropIfExists('game_predictors');
        Schema::dropIfExists('game_odds');
        Schema::dropIfExists('games');
    }
};
