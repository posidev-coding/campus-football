<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the "your account self-destructs in 3 days" mail went out — null means
 * it has not. A column rather than a computed window because the reminder must
 * be idempotent across reruns and missed days, and because pruning REQUIRES it:
 * `User::prunable()` refuses any account that was never warned, so the promise
 * in the mail ("3 days") is enforced by the query, not by scheduling luck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('verification_reminded_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('verification_reminded_at');
        });
    }
};
