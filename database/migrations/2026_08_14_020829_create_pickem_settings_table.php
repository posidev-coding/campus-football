<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The league's clock, editable without a deploy — the brand_settings
 * pattern: ONE row of overrides where every column is nullable and null
 * means "the shipped default" on App\Support\Cadence. The two moments it
 * governs: when a commissioner's unpublished board gets the standard slate
 * (the deadline), and when a week's results turn official (giving ESPN's
 * late stat corrections time to land before tiebreakers settle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickem_settings', function (Blueprint $table) {
            $table->id();
            // Carbon day-of-week: 0 = Sunday ... 6 = Saturday.
            $table->unsignedTinyInteger('slate_deadline_dow')->nullable();
            $table->time('slate_deadline_time')->nullable();
            $table->unsignedTinyInteger('official_final_dow')->nullable();
            $table->time('official_final_time')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickem_settings');
    }
};
