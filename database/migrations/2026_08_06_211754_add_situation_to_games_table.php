<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The live situation — possession, down and distance, red zone, timeouts,
 * the last play — rides the scoreboard competition we already fetch every
 * minute and was being discarded. It is what a live game page is FOR, and
 * like everything on the live tier it cannot be re-observed afterwards.
 *
 * No foreign key on possession_team_id, deliberately: `games` is rewritten
 * every minute all Saturday, the value is display-only, and ESPN uses
 * non-positive pseudo ids elsewhere — the sync maps those to null, but a
 * constraint would turn any future surprise into an aborted scoreboard
 * pass, which is the exact failure the per-event try/catch exists to stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedMediumInteger('possession_team_id')->nullable()->after('clock');
            $table->unsignedTinyInteger('down')->nullable()->after('possession_team_id');
            $table->unsignedTinyInteger('distance')->nullable()->after('down');
            $table->unsignedTinyInteger('yard_line')->nullable()->after('distance');
            $table->string('down_distance_text', 60)->nullable()->after('yard_line');
            $table->boolean('is_red_zone')->default(false)->after('down_distance_text');
            $table->string('last_play_text', 255)->nullable()->after('is_red_zone');
            $table->unsignedTinyInteger('home_timeouts')->nullable()->after('last_play_text');
            $table->unsignedTinyInteger('away_timeouts')->nullable()->after('home_timeouts');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn([
                'possession_team_id', 'down', 'distance', 'yard_line',
                'down_distance_text', 'is_red_zone', 'last_play_text',
                'home_timeouts', 'away_timeouts',
            ]);
        });
    }
};
