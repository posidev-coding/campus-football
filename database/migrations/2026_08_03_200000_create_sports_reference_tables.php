<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seasons, weeks, conferences, teams, venues — and the two season-scoped
 * pivots that the whole rebuild turns on.
 *
 * ESPN integer IDs are kept as primary keys on conferences, teams, and venues.
 * They are stable natural keys, they make upserts trivial, and they let us
 * join against ESPN payloads without a lookup table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            // 1 preseason, 2 regular, 3 postseason, 4 offseason — ESPN's own
            // season types, verified against the API.
            $table->unsignedTinyInteger('type');
            // "Regular Season", "Postseason" — 14 characters at the longest.
            $table->string('name', 40);
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->timestamps();

            $table->unique(['year', 'type']);
        });

        Schema::create('weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            // "Week 16", "Bowls" — 8 characters at the longest.
            $table->string('name', 40)->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->timestamps();

            $table->unique(['season_id', 'number']);
            // Resolving "which week does this kickoff belong to" is a date-range
            // scan on every game upsert.
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('conferences', function (Blueprint $table) {
            // ESPN group id.
            $table->unsignedSmallInteger('id')->primary();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('abbreviation', 20)->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_conference')->default(true);
            $table->timestamps();
        });

        /*
         * The fix for realignment, part one.
         *
         * ESPN re-parents its group tree every season: a conference's id, its
         * parent, and even its FBS/FCS classification are season-scoped facts,
         * not properties of the conference itself. Storing them on `conferences`
         * is what makes historical standings unreconstructable.
         */
        Schema::create('conference_seasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('conference_id');
            $table->unsignedSmallInteger('season_year');
            $table->unsignedSmallInteger('parent_group_id')->nullable();
            // FBS / FCS / DII-III, resolved by walking up the group tree.
            $table->string('classification', 20)->nullable();
            $table->timestamps();

            $table->foreign('conference_id')->references('id')->on('conferences')->cascadeOnDelete();
            $table->unique(['conference_id', 'season_year']);
            $table->index(['season_year', 'classification']);
        });

        Schema::create('venues', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 120);
            $table->string('city', 80)->nullable();
            $table->string('state', 10)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('indoor')->default(false);
            $table->boolean('grass')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->unsignedMediumInteger('id')->primary();
            // The route key: every team page resolves through this unique.
            $table->string('slug', 80)->unique();
            $table->string('location', 80)->nullable();
            $table->string('name', 60)->nullable();
            $table->string('nickname', 60)->nullable();
            $table->string('abbreviation', 20)->nullable();
            $table->string('display_name', 80);
            $table->string('short_display_name')->nullable();
            // Drives the --team-accent custom property on team and player pages.
            $table->string('color', 8)->nullable();
            $table->string('alt_color', 8)->nullable();
            $table->string('logo')->nullable();
            $table->string('logo_dark')->nullable();
            $table->timestamps();

            $table->index('display_name');
        });

        /*
         * The fix for realignment, part two — and the single most important
         * table in the rebuild.
         *
         * v3 stored `teams.conference_id` as one scalar, which cannot represent
         * a team that changed conferences. Verified against ESPN: Oregon is
         * group 54 (Pac-12) in 2021 and group 5 (Big Ten) in 2025. Every
         * conference-scoped query joins through here.
         */
        Schema::create('team_seasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('team_id');
            $table->unsignedSmallInteger('season_year');
            $table->unsignedSmallInteger('conference_id')->nullable();
            $table->unsignedSmallInteger('division_id')->nullable();
            $table->string('classification', 20)->nullable();
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('conference_id')->references('id')->on('conferences')->nullOnDelete();
            $table->unique(['team_id', 'season_year']);
            $table->index(['season_year', 'conference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_seasons');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('venues');
        Schema::dropIfExists('conference_seasons');
        Schema::dropIfExists('conferences');
        Schema::dropIfExists('weeks');
        Schema::dropIfExists('seasons');
    }
};
