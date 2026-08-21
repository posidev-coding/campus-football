<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settlement's write-once facts.
 *
 * A SETTLED slate is immutable history, so materializing its outcome is not
 * the leaderboard-cache the data-model rules forbid — it is the record.
 * `tiebreaker_actual` is the resolved answer to the week's question (null =
 * the stat never landed and the tiebreak was skipped); `final_points` and
 * `won` are each entrant's official standing, null until the official-final
 * moment stamps them — live standings stay SUMs over picks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slates', function (Blueprint $table) {
            $table->unsignedSmallInteger('tiebreaker_actual')->nullable()->after('tiebreaker_team_id');
        });

        Schema::table('slate_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('final_points')->nullable()->after('tiebreaker_total');
            // Nullable on purpose: null = not yet officially settled; a
            // settled loser is FALSE, and the difference is a real statement.
            $table->boolean('won')->nullable()->after('final_points');
        });
    }

    public function down(): void
    {
        Schema::table('slates', function (Blueprint $table) {
            $table->dropColumn('tiebreaker_actual');
        });

        Schema::table('slate_entries', function (Blueprint $table) {
            $table->dropColumn(['final_points', 'won']);
        });
    }
};
