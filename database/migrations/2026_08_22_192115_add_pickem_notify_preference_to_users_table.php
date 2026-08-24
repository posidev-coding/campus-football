<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent for pick'em mail, kept SEPARATE from the weekly digest's.
 *
 * Pick reminders and results are recurring, unrequested bulk mail, which is
 * exactly what Gmail's bulk-sender rules and CAN-SPAM want a one-click
 * unsubscribe on — the same treatment `newsletter_opt_in` already gets.
 *
 * It is a second column rather than a reuse because the two lists answer
 * different questions: somebody may well want to be told their picks are due
 * and not want the Sunday digest. One shared switch means the unsubscribe
 * that stops the digest silently kills the reminders they actually wanted,
 * which reads as a broken app rather than a preference.
 *
 * Defaults TRUE, like the newsletter: joining a pick'em group is itself the
 * request to be told about it. The indexed pair mirrors the recipient query's
 * shape, the way `users_newsletter_index` mirrors the newsletter sweep's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('pickem_notify_opt_in')->default(true)->after('unsubscribed_at');

            $table->index(['pickem_notify_opt_in', 'email_verified_at'], 'users_pickem_notify_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_pickem_notify_index');
            $table->dropColumn('pickem_notify_opt_in');
        });
    }
};
