<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin's header-style override. Null means the TeamPalette ladder
 * decides; a value pins one of its presets. Curated by hand in Filament and
 * deliberately absent from SyncTeams' payload, so an ESPN sync can never
 * clobber a choice a person made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('header_style', 20)->nullable()->after('alt_color');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('header_style');
        });
    }
};
