<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruits', function (Blueprint $table) {
            // A fourth rank ESPN publishes and we were dropping. Present on
            // 61-85% of a class depending on how far along it is.
            $table->unsignedSmallInteger('region_rank')->nullable()->after('state_rank');

            /*
             * `alternateId` — the id a prospect gets once they reach a college
             * roster — recorded even when we hold no such athlete yet.
             *
             * `athlete_id` can only be set for players we already have, so it
             * is 1000/1000 on the 2021 class and 164/1000 on 2026. Keeping the
             * raw id means a later roster sync can link the ones who enrol
             * without re-fetching every recruiting class.
             */
            $table->unsignedInteger('espn_athlete_id')->nullable()->after('athlete_id');

            // Declared, fillable, and written by nothing — 0 of 25 rows had a
            // value. ESPN's `analysis` is `[{id, type:"raw"}]`, never prose.
            $table->dropColumn('analysis');

            // Scout's prefix strategy has nothing to ride without this.
            $table->index('display_name');
        });

        // Grade is 0-100 today, which fits a tiny int — but the column is one
        // product decision away from overflowing and the byte is free.
        Schema::table('recruits', function (Blueprint $table) {
            $table->unsignedSmallInteger('grade')->nullable()->change();
        });

        /*
         * Every school in contention for a prospect.
         *
         * NOT a visit list, despite ESPN calling the date `visit`: only 659 of
         * 10,472 entries in the 2026 class carry one. It is an interest list
         * with an occasional visit attached, and the name says so.
         *
         * Costs no extra requests — it rides along in the payload the class
         * sync is already fetching.
         */
        Schema::create('recruit_schools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruit_id')->constrained()->cascadeOnDelete();

            /*
             * ESPN's team id, always present — and the upsert key.
             *
             * It cannot be `team_id`, which is nullable: MySQL treats every
             * NULL in a unique index as distinct, so a row for a school we do
             * not carry could never match on upsert and was re-inserted on
             * every run. Measured before the fix: re-upserting one such row
             * took a recruit from 21 interest rows to 22, and this syncs
             * weekly.
             *
             * It also keeps WHICH school it was when the team is not one we
             * hold, which a lone nullable FK throws away.
             */
            $table->unsignedMediumInteger('espn_team_id');

            /*
             * Nullable, and the row is stored even when the team is one we do
             * not carry. FCS and non-carried schools are a real part of who was
             * in on a recruit; dropping them would misreport the interest list
             * as narrower than it was.
             */
            $table->unsignedMediumInteger('team_id')->nullable();
            $table->string('status', 30)->nullable();

            /*
             * DATE, not timestamp. Seven rows in the 2026 class carry the year
             * 2205 — a plain ESPN typo for 2025 — and MySQL's timestamp tops
             * out at 2038-01-19, so a timestamp column would overflow on real
             * data. The sync drops implausible years rather than guessing at
             * the intended one, but the column type is the backstop.
             */
            $table->date('visited_on')->nullable();
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();

            // Verified over 6,868 live rows: a (recruit, school) pair never
            // repeats, so this is safe as the upsert key.
            $table->unique(['recruit_id', 'espn_team_id']);

            // "Which prospects was this school in on" — the team page's ask.
            $table->index(['team_id', 'recruit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruit_schools');

        Schema::table('recruits', function (Blueprint $table) {
            $table->dropIndex(['display_name']);
            $table->dropColumn(['region_rank', 'espn_athlete_id']);
            $table->text('analysis')->nullable();
        });
    }
};
