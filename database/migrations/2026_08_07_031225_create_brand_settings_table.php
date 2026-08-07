<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row, holding OVERRIDES only.
     *
     * Every column is nullable and null means "use the shipped default" —
     * the files in public/brand and the constants on App\Support\Brand, both
     * of which are in git. That is what makes a partial override safe, makes
     * "reset" a matter of nulling a column rather than restoring a fixture,
     * and means an override whose uploaded file has gone missing degrades to
     * the shipped brand rather than to a broken image.
     */
    public function up(): void
    {
        Schema::create('brand_settings', function (Blueprint $table) {
            $table->id();

            $table->string('name', 60)->nullable();
            $table->string('short_name', 30)->nullable();
            $table->string('tagline', 200)->nullable();

            /* The two lines of the wordmark: "CAMPUS" over "Football". */
            $table->string('wordmark_lead', 30)->nullable();
            $table->string('wordmark_main', 30)->nullable();

            /* Hex, with the leading #. Ink and Cream are the light/dark text
               and mark colors; Lager is the accent the stripes wear and the
               Filament panel's primary. */
            $table->string('color_ink', 7)->nullable();
            $table->string('color_cream', 7)->nullable();
            $table->string('color_lager', 7)->nullable();

            /* {asset key => path on the public disk}. One JSON column rather
               than a column per icon, so adding a size later is a change to
               Brand::SHIPPED and not a migration. Read by key only — MySQL's
               reordering of JSON object keys does not matter here. */
            $table->json('assets')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_settings');
    }
};
