<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this game's kickoff alert fanned out — null means it has not. The
 * dedup stamp for `cfb:kickoff-alerts`: the sweep runs every five minutes
 * across a fifteen-minute lookahead, so without a stamp every game would
 * alert two or three times. Per GAME, not per (game, user): the whole
 * audience fans out in one pass, so a keyed row per recipient would buy
 * nothing but table growth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->timestamp('kickoff_alert_sent_at')->nullable()->after('completed');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('kickoff_alert_sent_at');
        });
    }
};
