<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Game detail, news, national leaders, and the first user-owned sports
 * preference.
 *
 * The game tables all hang off ESPN's `summary` payload, which is the only
 * place a box score, a scoring play or a drive exists. That payload is 544 KB —
 * larger than a whole day's scoreboard — so it is fetched once per completed
 * game and, for a live game, at most once a minute no matter how many people
 * are watching.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Team-level box score. Fifteen stats per side: first downs, third down
         * efficiency, total yards, turnovers and so on.
         *
         * `display_stats` is a JSON *array* and carries the ordering, because
         * MySQL does not preserve object key order — the same reason
         * athlete_game_stats has one. A keyed map alone comes back reordered and
         * the box score silently scrambles.
         */
        Schema::create('game_team_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('game_id');
            $table->unsignedMediumInteger('team_id');
            $table->json('stats');
            $table->json('display_stats')->nullable();
            $table->timestamps();

            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->unique(['game_id', 'team_id']);
        });

        /*
         * The scoring summary.
         *
         * `sequence` is ours, not ESPN's: the payload is already in order but
         * carries no sortable field that survives a re-sync, and ordering by
         * period + clock is wrong because a game clock counts DOWN. Sorting on
         * clock ascending would put the end of a quarter first.
         */
        Schema::create('game_scoring_plays', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('game_id');
            $table->unsignedMediumInteger('team_id')->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->unsignedTinyInteger('period')->nullable();
            $table->string('clock', 10)->nullable();
            // e.g. "Rushing Touchdown", abbreviated "TD".
            $table->string('type', 60)->nullable();
            $table->string('abbreviation', 10)->nullable();
            $table->text('text')->nullable();
            $table->unsignedTinyInteger('home_score')->nullable();
            $table->unsignedTinyInteger('away_score')->nullable();
            $table->timestamps();

            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->unique(['game_id', 'sequence']);
        });

        /*
         * The summary's LIGHT half — what a game page actually renders, plus
         * the freshness bookkeeping the sync turns on.
         *
         * `is_final` is what makes the throttle safe to skip: once a game is
         * final its summary can never change, so it is fetched exactly once and
         * every later page view is a pure database read.
         *
         * Measured, and the reason `drives` now lives in its own table: with
         * drives inline this table was 1,764 MB from 4,844 rows — 86% of the
         * whole database — and the game page's `summary()->first()` is a
         * SELECT *, so every view dragged 306 KB of drive JSON across the wire
         * to render a box score that never reads it.
         */
        Schema::create('game_summaries', function (Blueprint $table) {
            $table->unsignedInteger('game_id')->primary();
            $table->json('win_probability')->nullable();
            $table->json('leaders')->nullable();
            $table->unsignedMediumInteger('attendance')->nullable();
            $table->string('scoring_plays_hash', 32)->nullable();
            $table->boolean('is_final')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->index(['is_final', 'synced_at']);
        });

        /*
         * Drives — 306 KB per game on average, 600 KB at the worst, and the
         * single largest thing this application stores.
         *
         * Split out rather than dropped: no screen renders them yet, but the
         * game page promises them ("Box score, scoring summary and drives"),
         * and re-fetching would cost one 544 KB request per game. In their own
         * table they cost nothing until the play-by-play tab asks for them.
         *
         * Never eager-load this alongside a game.
         */
        Schema::create('game_drives', function (Blueprint $table) {
            $table->unsignedInteger('game_id')->primary();
            $table->json('drives')->nullable();
            $table->timestamps();

            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
        });

        /*
         * News.
         *
         * ESPN's feed is a rolling window of roughly six days and it clamps
         * `limit` to 50 however much you ask for, so history here is
         * ACCUMULATED by syncing over time — it cannot be backfilled. Nothing in
         * the sync may delete: an article dropping out of ESPN's window must not
         * drop out of ours.
         */
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('espn_id')->unique();
            $table->string('headline');
            $table->text('description')->nullable();
            $table->string('byline')->nullable();
            // HeadlineNews, Story, Media, Preview...
            $table->string('type', 40)->nullable();
            // Both 512, and image_url is NOT the place to save 250 bytes:
            // measured at 242 characters across 6,153 articles, it was one
            // long ESPN CDN URL away from throwing under strict mode while
            // its sibling had already been widened.
            $table->string('image_url', 512)->nullable();
            $table->string('url', 512)->nullable();
            $table->boolean('premium')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('published_at');
        });

        /*
         * ESPN tags a national listicle with every team it mentions — a Top 25
         * preview carries 25 of them — so this pivot is wide by design.
         *
         * It also lists each team TWICE, once as "Georgia Bulldogs" and once as
         * "University of Georgia", both with the same teamId. The unique key is
         * what stops that becoming a duplicate row.
         */
        Schema::create('article_team', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedMediumInteger('team_id');
            $table->timestamps();

            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->unique(['article_id', 'team_id']);
            $table->index('team_id');
        });

        /*
         * National statistical leaders.
         *
         * One core-api request returns 13 categories of 100 athletes. The site
         * equivalent 404s, so this is the only source. Entries reference teams
         * across every division — 245 distinct teams in the 2025 feed — so
         * readers must scope through team_seasons.classification rather than
         * assume FBS.
         */
        Schema::create('national_leaders', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('season_year');
            $table->unsignedTinyInteger('season_type')->default(2);
            $table->string('category', 40);
            $table->unsignedInteger('athlete_id');
            $table->unsignedMediumInteger('team_id')->nullable();
            $table->unsignedSmallInteger('rank');
            $table->decimal('value', 12, 3)->nullable();
            $table->string('display_value', 40)->nullable();
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();

            /*
             * No foreign key to athletes, deliberately. The leaders feed names
             * players we may not have a record for yet — rosters only publish
             * the CURRENT season, so a 2021 leader has no roster row to have
             * come from. A constraint here would drop exactly the historical
             * leaders this table exists to hold; the athlete is resolved
             * afterwards and the display degrades to the team until it is.
             */
            $table->index('athlete_id');
            $table->unique(['season_year', 'season_type', 'category', 'rank'], 'national_leaders_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedMediumInteger('favorite_team_id')->nullable()->after('timezone');

            $table->foreign('favorite_team_id')->references('id')->on('teams')->nullOnDelete();
        });

        /*
         * Followed teams. A pivot rather than the JSON column v3 used, so that
         * "who follows this team" is an indexed query instead of a table scan —
         * it drives the per-team news sync, which only refreshes teams somebody
         * actually cares about.
         */
        Schema::create('team_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedMediumInteger('team_id');
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->unique(['user_id', 'team_id']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_follows');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['favorite_team_id']);
            $table->dropColumn('favorite_team_id');
        });

        Schema::dropIfExists('national_leaders');
        Schema::dropIfExists('article_team');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('game_drives');
        Schema::dropIfExists('game_summaries');
        Schema::dropIfExists('game_scoring_plays');
        Schema::dropIfExists('game_team_stats');
    }
};
