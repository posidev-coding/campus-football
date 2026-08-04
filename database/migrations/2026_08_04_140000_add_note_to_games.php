<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The event's own name — "Rose Bowl Presented by Prudential", "College Football
 * Playoff National Championship".
 *
 * `games.name` only ever holds "A at B", so without this a bowl is
 * indistinguishable from a Tuesday MAC game, and there is no way at all to tell
 * a playoff game from an ordinary one. ESPN carries it on every postseason event
 * as `competitions[0].notes[0].headline` — verified live, 41 of 41 for 2025,
 * with the 11 playoff games marked "College Football Playoff ..." — and we were
 * discarding it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('note')->nullable()->after('short_name');

            // The scoreboard splits the playoff out of the bowl slate on this.
            $table->index('note');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['note']);
            $table->dropColumn('note');
        });
    }
};
