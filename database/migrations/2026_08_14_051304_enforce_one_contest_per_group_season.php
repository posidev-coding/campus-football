<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE CONTEST PER GROUP PER SEASON — a group chooses its game at creation
 * and lives with it (one deliberate, announced pivot allowed per season).
 *
 * `mode_changed_at` null means "never pivoted": the once-per-season guard
 * is a whereNull, the settled_at idempotency grammar applied to a product
 * rule. The unique tightens from (group_id, season_year, mode) to
 * (group_id, season_year) so two modes cannot coexist even by bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Collapse the auto-both-modes era: CreateGroup used to mint one
         * contest per available mode, so every existing group carries a
         * duplicate. Keep the contest the clubhouse fronts — tiered over
         * classic over woodshed, the same FIELD() the screen resolves by —
         * and let the cascades take the loser's slates and picks. Dev-only
         * data by construction: the pickem flag has never been on for
         * non-admins.
         */
        DB::statement("
            DELETE c1 FROM contests c1
            JOIN contests c2
              ON c2.group_id = c1.group_id
             AND c2.season_year = c1.season_year
             AND FIELD(c2.mode, 'tiered', 'classic', 'woodshed')
               < FIELD(c1.mode, 'tiered', 'classic', 'woodshed')
        ");

        // New unique BEFORE dropping the old: the group_id foreign key
        // needs a usable index at every moment in between, and both
        // uniques lead with it.
        Schema::table('contests', function (Blueprint $table) {
            $table->timestamp('mode_changed_at')->nullable()->after('settings');
            $table->unique(['group_id', 'season_year']);
        });

        Schema::table('contests', function (Blueprint $table) {
            $table->dropUnique(['group_id', 'season_year', 'mode']);
        });
    }

    public function down(): void
    {
        // The deleted duplicates are gone for good — this only restores
        // the shape.
        Schema::table('contests', function (Blueprint $table) {
            $table->unique(['group_id', 'season_year', 'mode']);
        });

        Schema::table('contests', function (Blueprint $table) {
            $table->dropUnique(['group_id', 'season_year']);
            $table->dropColumn('mode_changed_at');
        });
    }
};
