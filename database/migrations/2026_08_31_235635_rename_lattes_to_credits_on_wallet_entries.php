<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The currency is a Tallboy now, and the ledger column stops saying otherwise.
 *
 * `credits` is deliberately NEUTRAL in the schema — the ContestMode precedent,
 * where backing values never carry the marketing name — so the NEXT rename is
 * copy alone and never touches data. `lattes` did not have that property, which
 * is the whole reason this migration exists.
 *
 * An ALTER rather than an edit to the create migration: the create ran months
 * ago against a database with rows in it, and a create rewritten in place
 * renames nothing that already exists. The rename is metadata-only in InnoDB —
 * no table copy, no downtime — and the leaderboard index does not name this
 * column, so nothing is rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_entries', function (Blueprint $table) {
            $table->renameColumn('lattes', 'credits');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_entries', function (Blueprint $table) {
            $table->renameColumn('credits', 'lattes');
        });
    }
};
