<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first time a session ran the app standalone — null means never. A
 * server-side stamp because it is the only install signal a browser tab can
 * ever read: the web clip inherits the session cookie but no client state,
 * and no platform tells a page whether its own domain is on a home screen.
 * The post-verify landing branches on it (an installed reader is coached
 * back to the app instead of being handed a second app in a tab). First
 * stamp wins, it is never rewritten, and uninstalling is invisible — the
 * column only ever ratchets on, which every consumer must assume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('standalone_seen_at')->nullable()->after('tour_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('standalone_seen_at');
        });
    }
};
