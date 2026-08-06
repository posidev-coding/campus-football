<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Players, their season and per-game production, and the team-level aggregates
 * that drive the team page.
 *
 * The same season-scoping discipline as conferences applies here, for the same
 * reason: the transfer portal means a player's team is a fact about a season,
 * not about the player.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('name');
            $table->string('abbreviation', 10)->nullable();
            $table->string('slug')->nullable();
            $table->unsignedSmallInteger('parent_id')->nullable();
            $table->timestamps();
        });

        Schema::create('athletes', function (Blueprint $table) {
            // ESPN athlete id.
            $table->unsignedInteger('id')->primary();
            $table->string('slug', 80)->nullable();
            $table->string('first_name', 60)->nullable();
            // last_name and display_name are both indexed AND are what
            // /players filesorts 13,580 rows by. MySQL sizes a sort row from
            // the DECLARED width, so 255 cost 1,020 bytes a row in utf8mb4
            // for a measured maximum of 20 and 27.
            $table->string('last_name', 60)->nullable();
            $table->string('display_name', 80);
            $table->string('short_name', 60)->nullable();
            // Left wide on purpose: a URL, measured at 75 but not ours to cap.
            $table->string('headshot_url')->nullable();
            $table->unsignedSmallInteger('height_in')->nullable();
            $table->string('display_height', 20)->nullable();
            $table->unsignedSmallInteger('weight_lb')->nullable();
            $table->string('display_weight', 20)->nullable();
            $table->string('birth_city', 80)->nullable();
            $table->string('birth_state', 40)->nullable();
            $table->string('birth_country', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('display_name');

            /*
             * `is_active` is NOT indexed. It is 99.7% true across 34,919
             * athletes, so an index on it can only ever hand back almost the
             * whole table — and search sorts by it rather than filtering on
             * it. Measured across a pass of every screen: one read.
             */
        });

        /*
         * A player's team for one season.
         *
         * Transfers are routine now, so a scalar `athletes.team_id` would break
         * exactly the way `teams.conference_id` did in v3 — and it would take
         * the player's stat history with it.
         */
        Schema::create('athlete_team_seasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('athlete_id');
            $table->unsignedMediumInteger('team_id');
            $table->unsignedSmallInteger('season_year');
            $table->string('jersey', 10)->nullable();
            $table->unsignedSmallInteger('position_id')->nullable();
            // ESPN groups a roster into offense/defense/specialTeam/
            // injuredReserveOrOut/suspended/practiceSquad.
            $table->string('position_group', 30)->nullable();
            $table->string('experience_class', 20)->nullable();
            $table->string('status', 20)->nullable();
            $table->timestamps();

            $table->foreign('athlete_id')->references('id')->on('athletes')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('position_id')->references('id')->on('positions')->nullOnDelete();

            $table->unique(['athlete_id', 'season_year']);
            $table->index(['team_id', 'season_year', 'position_group']);
        });

        Schema::create('athlete_season_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('athlete_id');
            $table->unsignedSmallInteger('season_year');
            $table->unsignedTinyInteger('season_type')->default(2);
            $table->unsignedMediumInteger('team_id')->nullable();
            $table->string('category', 40);
            // Keyed by ESPN stat name. Categories carry wildly different stats,
            // and a column per stat would be a hundred mostly-null columns.
            $table->json('stats');
            $table->json('display_stats')->nullable();
            $table->timestamps();

            $table->foreign('athlete_id')->references('id')->on('athletes')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->unique(['athlete_id', 'season_year', 'season_type', 'category'], 'athlete_season_stats_unique');

            /*
             * Every leaderboard reads this table, and none of them knows an
             * athlete id — they ask for a season, a type, a category and a set
             * of teams. The unique above leads with `athlete_id`, so none of
             * that could use it: MySQL fell back to the `team_id` foreign-key
             * index and scanned 11,337 rows at 0.1% selectivity. Measured over
             * one pass of the app's screens, this table served 1,821,000 row
             * reads.
             *
             * Column order follows the filter exactly, most selective last:
             * team_id trails because a division scope passes hundreds of ids
             * while season/type/category are always single equality matches.
             */
            $table->index(['season_year', 'season_type', 'category', 'team_id'], 'athlete_season_stats_leaderboard');
        });

        Schema::create('athlete_game_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('athlete_id');
            $table->unsignedInteger('game_id');
            $table->unsignedMediumInteger('team_id')->nullable();
            $table->string('category', 40);
            $table->json('stats');
            $table->json('display_stats')->nullable();
            $table->timestamps();

            $table->foreign('athlete_id')->references('id')->on('athletes')->cascadeOnDelete();
            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->unique(['athlete_id', 'game_id', 'category'], 'athlete_game_stats_unique');
        });

        Schema::create('team_season_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('team_id');
            $table->unsignedSmallInteger('season_year');
            $table->unsignedTinyInteger('season_type')->default(2);
            $table->string('category', 40);
            $table->json('stats');
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->unique(['team_id', 'season_year', 'season_type', 'category'], 'team_season_stats_unique');
        });

        /*
         * Denormalized deliberately. The team page reads leaders directly
         * rather than aggregating athlete_season_stats at request time, which
         * on a scale-to-zero database is the difference between one indexed
         * read and a fan-out.
         */
        Schema::create('team_leaders', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('team_id');
            $table->unsignedSmallInteger('season_year');
            $table->unsignedTinyInteger('season_type')->default(2);
            $table->string('category', 40);
            $table->unsignedInteger('athlete_id')->nullable();
            $table->decimal('value', 10, 2)->nullable();
            $table->string('display_value', 60)->nullable();
            $table->unsignedTinyInteger('rank')->default(1);
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('athlete_id')->references('id')->on('athletes')->nullOnDelete();
            $table->unique(['team_id', 'season_year', 'season_type', 'category', 'rank'], 'team_leaders_unique');
        });

        Schema::create('coaches', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name');
            $table->timestamps();
        });

        Schema::create('coach_team_seasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('coach_id');
            $table->unsignedMediumInteger('team_id');
            $table->unsignedSmallInteger('season_year');
            $table->unsignedTinyInteger('experience')->nullable();
            $table->timestamps();

            $table->foreign('coach_id')->references('id')->on('coaches')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->unique(['coach_id', 'team_id', 'season_year']);
        });

        /*
         * High school recruiting. ESPN publishes this at a path that 404s on
         * every obvious guess and only resolves at
         * .../leagues/college-football/recruiting/{year}/athletes — 5,193
         * prospects for the 2026 class.
         *
         * athlete_id is nullable: a prospect often has no athlete record until
         * they appear on a college roster.
         */
        Schema::create('recruits', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('espn_id')->nullable();
            $table->unsignedInteger('athlete_id')->nullable();
            $table->unsignedSmallInteger('recruiting_class');
            $table->string('display_name');
            $table->unsignedTinyInteger('grade')->nullable();
            $table->unsignedSmallInteger('national_rank')->nullable();
            $table->unsignedSmallInteger('position_rank')->nullable();
            $table->unsignedSmallInteger('state_rank')->nullable();
            $table->string('status', 30)->nullable();
            $table->unsignedMediumInteger('committed_team_id')->nullable();
            $table->string('high_school', 120)->nullable();
            $table->string('hometown_city', 80)->nullable();
            $table->string('hometown_state', 40)->nullable();
            $table->unsignedSmallInteger('position_id')->nullable();
            $table->unsignedSmallInteger('height_in')->nullable();
            $table->unsignedSmallInteger('weight_lb')->nullable();
            $table->text('analysis')->nullable();
            $table->timestamps();

            $table->foreign('athlete_id')->references('id')->on('athletes')->nullOnDelete();
            $table->foreign('committed_team_id')->references('id')->on('teams')->nullOnDelete();
            $table->foreign('position_id')->references('id')->on('positions')->nullOnDelete();

            $table->unique(['espn_id', 'recruiting_class']);
            $table->index(['recruiting_class', 'national_rank']);
            $table->index(['committed_team_id', 'recruiting_class']);
        });

        Schema::create('injuries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('athlete_id');
            $table->unsignedMediumInteger('team_id')->nullable();
            $table->string('status', 40)->nullable();
            $table->string('type', 60)->nullable();
            $table->string('detail')->nullable();
            $table->string('side', 20)->nullable();
            $table->date('return_date')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->foreign('athlete_id')->references('id')->on('athletes')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->index(['team_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('injuries');
        Schema::dropIfExists('recruits');
        Schema::dropIfExists('coach_team_seasons');
        Schema::dropIfExists('coaches');
        Schema::dropIfExists('team_leaders');
        Schema::dropIfExists('team_season_stats');
        Schema::dropIfExists('athlete_game_stats');
        Schema::dropIfExists('athlete_season_stats');
        Schema::dropIfExists('athlete_team_seasons');
        Schema::dropIfExists('athletes');
        Schema::dropIfExists('positions');
    }
};
