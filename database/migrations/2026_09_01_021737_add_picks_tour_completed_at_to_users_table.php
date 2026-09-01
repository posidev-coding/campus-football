<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Picks walk's OWN completion stamp.
 *
 * TWO COLUMNS, NOT ONE, and the distinction is the point.
 * `picks_first_seen_at` is the shared fact — it is what switches the
 * Tallboy economy on and pays the weekly top-off — while this column is one
 * tour's business. Dismissing a walk and having arrived at Picks are
 * different things, and folding them together would mean a replay from
 * Account re-triggered a grant, or a reader who waved the coach marks away
 * looked to the economy like somebody who had never turned up.
 *
 * Nullable and never backfilled: an existing reader has not seen this walk,
 * so a null is the honest answer and they get it once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('picks_tour_completed_at')->nullable()->after('picks_first_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('picks_tour_completed_at');
        });
    }
};
