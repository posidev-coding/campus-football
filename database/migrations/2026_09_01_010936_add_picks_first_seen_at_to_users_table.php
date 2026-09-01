<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the reader first arrived at Picks — the moment the Tallboy economy
 * switches on for them.
 *
 * Deliberately NOT signup. Credits are earned everywhere and spent only in
 * the Lobby, so stocking a wallet before anybody has met the currency pays
 * for a promise the reader has not read yet; the weekly top-off starts from
 * the visit instead. The stamp is also the once-ever fact a first-visit tour
 * hangs off, which is why it is a VISIT column and not a tour column —
 * dismissing a tour and having arrived at Picks are different things, and a
 * replay must never re-trigger a grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('picks_first_seen_at')->nullable()->after('standalone_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('picks_first_seen_at');
        });
    }
};
