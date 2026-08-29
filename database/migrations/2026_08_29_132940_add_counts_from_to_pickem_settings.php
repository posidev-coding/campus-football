<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE PRACTICE WINDOW, as one date on the league clock.
 *
 * `slates.exhibition` has existed since the Saturday anchor landed — real
 * picks, real grading, real XP, no season credit — and nothing has ever
 * written it. A launch needs weeks that do not count: the rehearsal
 * Saturday and the first public one are for finding out what breaks, not
 * for deciding somebody's season.
 *
 * A DATE, not a week number: ESPN's Week 1 holds two Saturdays and a week
 * number means nothing across seasons, while "the first Saturday that
 * counts" is the same sentence every year. Null is not a missing value —
 * it is NO practice window, which is the honest state for every season
 * after the launch one, and the shipped default so an unconfigured
 * install counts everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickem_settings', function (Blueprint $table) {
            $table->date('counts_from')->nullable()->after('official_final_time');
        });
    }

    public function down(): void
    {
        Schema::table('pickem_settings', function (Blueprint $table) {
            $table->dropColumn('counts_from');
        });
    }
};
