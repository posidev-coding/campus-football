<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wallet, as a ledger. One row per thing that earned or spent — never a
 * running balance column, which is a cache that can drift from its own
 * history. Totals are SUMs; Phase 7 (gamification proper) extends this table
 * rather than replacing it.
 *
 * `reason` is the audit label and REPEATS — a weekly win pays out every week,
 * a contest entry spends every entry. `key` is the idempotency handle for
 * one-time grants (verification, the first-team seed): unique per user, so a
 * double-fired event inserts zero rows instead of paying twice. Null for
 * repeatable entries — MySQL permits any number of NULLs under UNIQUE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Signed on purpose: spending is a negative row in the same ledger.
            $table->integer('xp');
            $table->integer('lattes');
            $table->string('reason', 40);
            $table->string('key', 40)->nullable();
            // No updated_at — a ledger row is immutable once written.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_entries');
    }
};
