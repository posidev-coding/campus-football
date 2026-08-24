<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The quality score, and everything it was made of, frozen onto the row at
 * publish time.
 *
 * This exists because the score CANNOT be calibrated from history. Three of
 * its five components — matchup quality (60 of the 100 points), spread
 * tightness (20) and line movement (5) — ride ESPN feeds that are
 * current-window only. Measured 2026-08-24: 4,847 completed games across
 * 2021–2025 carry zero `matchup_quality` and zero odds of any kind, against
 * 946 games in 2026 that carry both. `matchup_quality` is a rolling two-week
 * window; nothing backfills it, and nothing ever will.
 *
 * So the weights in GameQualityScore's docblock — "a first calibration,
 * expected to be tuned against a real season's slates" — have no data to be
 * tuned against, and no replacement (regression, LLM or otherwise) can be
 * trained or validated either. The only fix is to write the features down as
 * they happen. Every published slate then becomes a labeled row: the features
 * here, the outcome (pick split, final margin against the frozen line) already
 * on picks and slate_entries.
 *
 * SLATES PUBLISHED BEFORE THIS EXISTS ARE GONE AS CALIBRATION DATA FOREVER.
 * That is the whole reason it ships now rather than with the re-fit.
 *
 * NULL means "could not be scored", never zero — a game with no usable current
 * line at publish is unmeasured, not bad. Tightness and movement stay
 * recomputable later regardless, from the spread / market_spread /
 * odds_provider / odds_captured_at this table already froze.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slate_games', function (Blueprint $table) {
            // 0–100 with two decimals, the same precision round($score, 2)
            // has always returned.
            $table->decimal('quality', 5, 2)->nullable()->after('tier');
            // RAW inputs beside the weighted parts, under a version token. A
            // re-fit needs the feature, not the product — the weights are
            // exactly what it is solving for.
            $table->json('quality_parts')->nullable()->after('quality');
        });
    }

    public function down(): void
    {
        Schema::table('slate_games', function (Blueprint $table) {
            $table->dropColumn(['quality', 'quality_parts']);
        });
    }
};
