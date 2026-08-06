<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stadium photo for the game screen's information card.
 *
 * ESPN publishes these on its CDN but does not hand them to us in any feed
 * a pregame screen can reach — the summary payload carries `gameInfo.venue.
 * images`, and an unplayed game has no summary. Nor is the URL derivable:
 * measured across six venues, three resolve only under `day/interior`, one
 * only under `day`, two under both, and one has no photo at all. So the
 * column is filled by probing the CDN once per venue and storing only what
 * answers 200 — the same treatment coach headshots get, and for the same
 * reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->string('image_url', 512)->nullable()->after('grass');
            // Separates "no photo exists" from "never asked", so a venue
            // without one is not re-probed on every run.
            $table->timestamp('image_checked_at')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'image_checked_at']);
        });
    }
};
