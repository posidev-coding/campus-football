<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The dirty-guard for the scoring summary: storeScoringPlays() replaces
     * rows wholesale, and under the two-minute live sweep an unchanged payload
     * would churn every scoring row all Saturday. The stored hash lets the
     * sync skip the rewrite; corrections change the hash, so they still land.
     */
    public function up(): void
    {
        Schema::table('game_summaries', function (Blueprint $table) {
            $table->string('scoring_plays_hash', 32)->nullable()->after('attendance');
        });
    }

    public function down(): void
    {
        Schema::table('game_summaries', function (Blueprint $table) {
            $table->dropColumn('scoring_plays_hash');
        });
    }
};
