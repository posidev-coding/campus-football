<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When we last ASKED ESPN for this athlete's game log.
     *
     * Not "when we last got rows". Most athletes have no game log at all, so
     * treating an empty `athlete_game_stats` as "never fetched" would dispatch
     * a job on every view of every player who never took a snap, forever. Same
     * distinction `articles.story_fetched_at` draws for the third of articles
     * that are video posts with no body.
     *
     * Persisted rather than cached: a `cache:clear` would otherwise re-open the
     * tap on all 34,836 of them at once.
     */
    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->timestamp('game_log_fetched_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->dropColumn('game_log_fetched_at');
        });
    }
};
