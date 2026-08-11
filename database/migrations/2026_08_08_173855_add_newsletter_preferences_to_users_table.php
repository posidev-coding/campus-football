<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether somebody wants the weekly email.
     *
     * Two columns rather than one, because they answer different questions and
     * only the second one is a fact. `newsletter_opt_in` is the current
     * setting, flipped from Account or from an unsubscribe link;
     * `unsubscribed_at` records that they once said no, which survives them
     * turning it back on and is the thing to check before ever re-enrolling
     * anybody in anything.
     *
     * TRANSACTIONAL mail ignores both. A password reset is not a list.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /* Default on: signing up for a football app is opting into hearing
               about football. The unsubscribe link is what makes that fair, and
               it is in every send. */
            $table->boolean('newsletter_opt_in')->default(true)->after('content_rating');

            $table->timestamp('unsubscribed_at')->nullable()->after('newsletter_opt_in');
        });

        /* The weekly send asks exactly one question — "who gets this" — and it
           asks it of every row in the table. Without this it is a full scan
           that grows with signups. */
        Schema::table('users', function (Blueprint $table) {
            $table->index(['newsletter_opt_in', 'email_verified_at'], 'users_newsletter_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_newsletter_index');
            $table->dropColumn(['newsletter_opt_in', 'unsubscribed_at']);
        });
    }
};
