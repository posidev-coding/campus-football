<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Search ordered athletes (34,836 rows) and coaches by a CORRELATED
 * SUBQUERY per matching row — max(season_year) re-derived on every
 * keystroke, six queries deep. The newest season an athlete or coach has
 * is a FACT the season tables already know at write time, so it lives as
 * a denormalized, indexed column stamped by the model events on
 * AthleteTeamSeason / CoachTeamSeason (one door, so no sync writer can
 * forget it). Backfilled here from the season tables themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->smallInteger('latest_season_year')->unsigned()->nullable()->after('is_active')->index();
        });

        Schema::table('coaches', function (Blueprint $table) {
            $table->smallInteger('latest_season_year')->unsigned()->nullable()->index();
        });

        DB::statement('
            UPDATE athletes a
            JOIN (SELECT athlete_id, MAX(season_year) AS latest FROM athlete_team_seasons GROUP BY athlete_id) s
              ON s.athlete_id = a.id
            SET a.latest_season_year = s.latest
        ');

        DB::statement('
            UPDATE coaches c
            JOIN (SELECT coach_id, MAX(season_year) AS latest FROM coach_team_seasons GROUP BY coach_id) s
              ON s.coach_id = c.id
            SET c.latest_season_year = s.latest
        ');
    }

    public function down(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->dropColumn('latest_season_year');
        });

        Schema::table('coaches', function (Blueprint $table) {
            $table->dropColumn('latest_season_year');
        });
    }
};
