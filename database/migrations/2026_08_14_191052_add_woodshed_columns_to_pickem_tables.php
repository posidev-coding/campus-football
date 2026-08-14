<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE WOODSHED'S FOOTPRINT — the founders' rules, recovered (email + the
 * 2016 code), need four things the schema could not say:
 *
 * SIGNED points. A wrong Lock is worth MINUS four, so `picks.points` and
 * `slate_entries.final_points` drop their unsigned-ness. Everywhere else
 * the meaning holds: null = ungraded, 0 = graded and earned nothing —
 * and now a negative number is a Lock that backfired, a real result.
 *
 * The Lock wager. `picks.locked` is the player's deliberate stake on the
 * featured game (+6 right, −4 wrong), a stored CHOICE — distinct from the
 * temporal kickoff lock, which stays a clock check and never a column.
 *
 * The Bear. `slates.bear_theme` names the week's shtick and
 * `slate_games.bear_team_id` records his side of every matchup, stamped
 * at publish by BearPicks. Null on both means "this slate has no Bear"
 * (every non-Woodshed slate) — never a default. `slate_entries.beat_bear`
 * is stamped at settlement: null = no Bear to beat, true/false = the
 * strict comparison's verdict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('picks', function (Blueprint $table) {
            // change() drops modifiers it does not restate: nullable stays.
            $table->tinyInteger('points')->nullable()->change();
            // The Lock WAGER (false = never staked). Default, not nullable:
            // unlike bear columns this is a player choice with a real "no".
            $table->boolean('locked')->default(false)->after('picked_team_id');
        });

        Schema::table('slate_entries', function (Blueprint $table) {
            $table->smallInteger('final_points')->nullable()->change();
            $table->boolean('beat_bear')->nullable()->after('won');
        });

        Schema::table('slates', function (Blueprint $table) {
            // Backing value of a BearPicks::THEMES entry.
            $table->string('bear_theme', 20)->nullable()->after('tiebreaker_actual');
        });

        Schema::table('slate_games', function (Blueprint $table) {
            // Same width and delete posture as favorite_team_id beside it.
            $table->unsignedMediumInteger('bear_team_id')->nullable()->after('favorite_team_id');
            $table->foreign('bear_team_id')->references('id')->on('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('slate_games', function (Blueprint $table) {
            $table->dropForeign(['bear_team_id']);
            $table->dropColumn('bear_team_id');
        });

        Schema::table('slates', function (Blueprint $table) {
            $table->dropColumn('bear_theme');
        });

        Schema::table('slate_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('final_points')->nullable()->change();
            $table->dropColumn('beat_bear');
        });

        Schema::table('picks', function (Blueprint $table) {
            $table->unsignedTinyInteger('points')->nullable()->change();
            $table->dropColumn('locked');
        });
    }
};
