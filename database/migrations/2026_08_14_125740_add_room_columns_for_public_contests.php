<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRANSIENT PUBLIC ROOMS — a public contest is a groups row wearing
 * kind = 'lobby' plus these three columns: `week_id` set means "one
 * week's room" (null keeps a season-long group), `member_cap` bounds the
 * seats (null = uncapped private), and `filled_at` is the SPAWN CLAIM —
 * the whereNull-then-update stamp that makes exactly ONE of two racing
 * joiners spawn the next room, the settled_at grammar again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('week_id')->nullable()->after('kind')->constrained();
            $table->unsignedSmallInteger('member_cap')->nullable()->after('week_id');
            $table->timestamp('filled_at')->nullable()->after('member_cap');

            // The lobby's inventory query: open rooms for a week.
            $table->index(['kind', 'week_id', 'filled_at']);
        });

        Schema::table('pickem_settings', function (Blueprint $table) {
            // Null resolves to Group::DEFAULT_LOBBY_CAP — the one-row
            // override pattern the league clock already uses.
            $table->unsignedSmallInteger('lobby_member_cap')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['kind', 'week_id', 'filled_at']);
            $table->dropConstrainedForeignId('week_id');
            $table->dropColumn(['member_cap', 'filled_at']);
        });

        Schema::table('pickem_settings', function (Blueprint $table) {
            $table->dropColumn('lobby_member_cap');
        });
    }
};
