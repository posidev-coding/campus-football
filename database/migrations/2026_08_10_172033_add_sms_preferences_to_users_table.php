<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A phone number, whether it has been proved, and whether they said yes.
     *
     * Four columns because SMS consent is a different thing from an email
     * preference, and conflating them is how an app ends up texting somebody
     * who never agreed to be texted:
     *
     * - `sms_opt_in` defaults to FALSE, unlike `newsletter_opt_in`. Signing up
     *   for a football app can fairly be read as wanting email about football;
     *   it cannot be read as handing over a phone. US carriers and the TCPA
     *   both want express consent, and a default of true is not consent.
     * - `sms_opted_in_at` is the RECORD that consent happened, and it survives
     *   an opt-out. It is what a carrier asks for when vetting the 10DLC
     *   campaign, and turning the switch back off does not make it untrue.
     * - `phone_verified_at` is separate from consent because they fail
     *   differently. An unverified number is very likely a stranger's phone —
     *   one mistyped digit — and unlike a bounced email, they experience it as
     *   spam rather than as nothing.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /* E.164, so +14155550123 — 16 characters covers every country code
               and leaves room. Stored normalized, never as typed: a number that
               round-trips differently is a number that cannot be matched to an
               inbound STOP. */
            $table->string('phone', 16)->nullable()->after('email_verified_at');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');

            $table->boolean('sms_opt_in')->default(false)->after('unsubscribed_at');
            $table->timestamp('sms_opted_in_at')->nullable()->after('sms_opt_in');
        });

        Schema::table('users', function (Blueprint $table) {
            /* An inbound STOP arrives as a phone number and nothing else — no
               session, no user id — so the webhook's only way in is this. */
            $table->index('phone', 'users_phone_index');

            /* "Who can be texted" is the send's one question, asked of every
               row. Both halves are required: consent without a verified number
               is a number we must not use. */
            $table->index(['sms_opt_in', 'phone_verified_at'], 'users_sms_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_sms_index');
            $table->dropIndex('users_phone_index');
            $table->dropColumn(['phone', 'phone_verified_at', 'sms_opt_in', 'sms_opted_in_at']);
        });
    }
};
