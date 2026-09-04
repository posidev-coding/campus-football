<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the practice window is FOR.
 *
 * `counts_from` is one date for the whole league, and the launch showed
 * why that is not enough: the founder's rehearsal weekend is for the
 * private groups learning the app, while the public rooms are the shop
 * window and count from the first Saturday they open. One global date
 * made the rooms rehearse too, silently.
 *
 * Not nullable, unlike every other override on this row: the other
 * columns answer "what time?", where blank can honestly mean "the shipped
 * default". This one answers a yes-or-no the admin can always see the
 * state of, and false is a decision — private groups practice, public
 * rooms count — not an absent value. It does nothing at all while
 * `counts_from` is null, which is every season after a launch one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickem_settings', function (Blueprint $table) {
            $table->boolean('practice_includes_rooms')->default(false)->after('counts_from');
        });
    }

    public function down(): void
    {
        Schema::table('pickem_settings', function (Blueprint $table) {
            $table->dropColumn('practice_includes_rooms');
        });
    }
};
