<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The coach sync's columns. Coaches arrived name-only from the roster feed;
 * the per-coach core endpoint adds a birthplace, a career record, and a
 * season-by-season tenure record.
 *
 * `birth_state` stores the TWO-LETTER code, normalized on write — ESPN sends
 * coaches a full state name ("Montgomery, Alabama") while athletes get codes
 * ("TX"), and a results list showing both formats side by side reads broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coaches', function (Blueprint $table) {
            $table->string('headshot_url')->nullable()->after('display_name');
            $table->date('date_of_birth')->nullable()->after('headshot_url');
            $table->string('birth_city')->nullable()->after('date_of_birth');
            $table->string('birth_state', 40)->nullable()->after('birth_city');
            $table->string('birth_country', 40)->nullable()->after('birth_state');
            $table->unsignedTinyInteger('experience_years')->nullable()->after('birth_country');
            $table->unsignedSmallInteger('career_wins')->nullable()->after('experience_years');
            $table->unsignedSmallInteger('career_losses')->nullable()->after('career_wins');
            $table->unsignedSmallInteger('career_ties')->nullable()->after('career_losses');
        });

        Schema::table('coach_team_seasons', function (Blueprint $table) {
            $table->unsignedTinyInteger('wins')->nullable()->after('experience');
            $table->unsignedTinyInteger('losses')->nullable()->after('wins');
            $table->unsignedTinyInteger('ties')->nullable()->after('losses');
        });
    }

    public function down(): void
    {
        Schema::table('coaches', function (Blueprint $table) {
            $table->dropColumn([
                'headshot_url', 'date_of_birth', 'birth_city', 'birth_state',
                'birth_country', 'experience_years', 'career_wins', 'career_losses', 'career_ties',
            ]);
        });

        Schema::table('coach_team_seasons', function (Blueprint $table) {
            $table->dropColumn(['wins', 'losses', 'ties']);
        });
    }
};
