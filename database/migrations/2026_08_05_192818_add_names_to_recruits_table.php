<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split names, so a prospect can be found by surname.
     *
     * Scout's prefix strategy matches from the START of a field, so indexing
     * `display_name` alone means "Brewster" cannot find "Jalen Brewster" —
     * verified, it returned nothing while the full name returned the row.
     * Athletes have always indexed `last_name` alongside for exactly this, and
     * a recruit search that only answers to first names is not a search.
     *
     * ESPN carries `firstName` and `lastName` in the payload already, so this
     * costs no extra requests — only a re-sync of the classes we hold.
     */
    public function up(): void
    {
        Schema::table('recruits', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('display_name');
            $table->string('last_name')->nullable()->after('first_name');

            $table->index('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('recruits', function (Blueprint $table) {
            $table->dropIndex(['last_name']);
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
