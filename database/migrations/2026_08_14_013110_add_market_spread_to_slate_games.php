<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The league's half-point law changed what `slate_games.spread` IS: not a
 * copy of the market's number but the COMMISSIONER'S contest line — always
 * a half point so no pick can ever push, adjustable up to three points
 * either side of the book. `market_spread` keeps the book's own number the
 * line was set against, so the deviation is auditable and the builder can
 * show "book -7, yours -7.5" side by side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slate_games', function (Blueprint $table) {
            $table->decimal('market_spread', 5, 1)->nullable()->after('spread');
        });
    }

    public function down(): void
    {
        Schema::table('slate_games', function (Blueprint $table) {
            $table->dropColumn('market_spread');
        });
    }
};
