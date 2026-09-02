<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A broadcast network's mark — ESPN, SEC Network, ABC — keyed by the short
 * name the scoreboard's `broadcasts[].names` already writes onto every game.
 * `games.broadcasts` stays the list of names it always was; the artwork is
 * looked up beside it, once per screen (App\Support\Networks).
 *
 * Both logo columns are nullable and STAY null for the networks ESPN ships
 * no artwork for — FOX, CBS, NBC, FS1 and BTN in every feed measured on
 * 2026-09-02. A network without a logo renders its name, and nothing writes
 * a stand-in. `name` is right-sized because it is the unique key; the URLs
 * are left wide (data-model.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('networks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('logo')->nullable();
            $table->string('logo_dark')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('networks');
    }
};
