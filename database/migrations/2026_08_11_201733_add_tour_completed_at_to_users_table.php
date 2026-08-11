<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the guided coach-mark tour was finished or skipped — null means it has
 * not run yet, and Home offers it.
 *
 * A first-class column like every user preference here, not a JSON bag. And
 * deliberately NOT `onboarded_at`: that stamp already carries three meanings
 * (CTA dismissed, wizard finished, first team followed), and a fourth would
 * make dismissing a card also silence the tour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('tour_completed_at')->nullable()->after('onboarded_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tour_completed_at');
        });
    }
};
