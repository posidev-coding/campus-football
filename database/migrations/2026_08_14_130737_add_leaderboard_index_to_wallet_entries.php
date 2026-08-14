<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The XP leaderboard is windowed SUMs over wallet_entries — This Week /
 * This Season / All-Time, grouped by user. A covering index on
 * (created_at, user_id, xp) lets every window resolve from the index
 * alone: range-scan the window, group on the second column, sum the
 * third, zero row reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_entries', function (Blueprint $table) {
            $table->index(['created_at', 'user_id', 'xp'], 'wallet_entries_leaderboard_index');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_entries', function (Blueprint $table) {
            $table->dropIndex('wallet_entries_leaderboard_index');
        });
    }
};
