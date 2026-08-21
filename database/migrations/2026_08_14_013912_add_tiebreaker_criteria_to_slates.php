<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tiebreaker grows from one hardcoded question into a QUESTION WITH A
 * METRIC — the paper league changed its criterion week to week ("total
 * passing yards for Auburn", "combined points, UT and LSU"), and the app
 * automates the same idea: a metric, its game, and — when the metric is
 * about one side — the team it is about. Entrants still answer with one
 * number; settlement resolves the actual from data we already hold.
 *
 * Null metric means "not designated yet", the same statement a null
 * tiebreaker game makes; existing designated rows backfill to the combined
 * points question they were asking implicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slates', function (Blueprint $table) {
            // Backing value of App\Enums\TiebreakerMetric.
            $table->string('tiebreaker_metric', 20)->nullable()->after('tiebreaker_slate_game_id');
            $table->unsignedMediumInteger('tiebreaker_team_id')->nullable()->after('tiebreaker_metric');

            $table->foreign('tiebreaker_team_id')->references('id')->on('teams')->nullOnDelete();
        });

        DB::table('slates')
            ->whereNotNull('tiebreaker_slate_game_id')
            ->update(['tiebreaker_metric' => 'combined_points']);
    }

    public function down(): void
    {
        Schema::table('slates', function (Blueprint $table) {
            $table->dropForeign(['tiebreaker_team_id']);
            $table->dropColumn(['tiebreaker_metric', 'tiebreaker_team_id']);
        });
    }
};
