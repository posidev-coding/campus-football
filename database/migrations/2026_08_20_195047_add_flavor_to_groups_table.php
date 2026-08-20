<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which specialty room this lobby group is, when it is one.
     *
     * Null means a STANDARD room — one of the three the preflight's red
     * line watches — so the column needs no backfill and no index: the
     * hot path already rides (kind, week_id, filled_at), and flavor is a
     * residual filter over a handful of rows. The values are
     * App\Enums\LobbyFlavor backings; the rules a flavor implies live in
     * contests.settings, stamped at spawn, so a room's flavor is identity
     * and its contest's settings are law.
     */
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('flavor', 20)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('flavor');
        });
    }
};
