<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The predictor payload carries more than the quality metrics: a projected
 * margin (`teamPredPtDiff`, signed per side) and each team's strength-of-
 * opposition FBS rank. Both were being fetched and discarded, and like the
 * rest of this feed they cannot be backfilled — ESPN serves predictors for
 * upcoming games only, so anything not captured before kickoff is gone.
 *
 * `teamChanceLoss` is NOT stored: it is the complement of the projection,
 * and a derived number written down is a number that can disagree with its
 * source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_predictors', function (Blueprint $table) {
            $table->decimal('home_pred_pt_diff', 5, 2)->nullable()->after('away_projection');
            $table->decimal('away_pred_pt_diff', 5, 2)->nullable()->after('home_pred_pt_diff');
            $table->unsignedSmallInteger('home_opp_strength_rank')->nullable()->after('away_opp_strength');
            $table->unsignedSmallInteger('away_opp_strength_rank')->nullable()->after('home_opp_strength_rank');
        });
    }

    public function down(): void
    {
        Schema::table('game_predictors', function (Blueprint $table) {
            $table->dropColumn([
                'home_pred_pt_diff', 'away_pred_pt_diff',
                'home_opp_strength_rank', 'away_opp_strength_rank',
            ]);
        });
    }
};
