<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A pick'em week IS a Saturday, and the schema says so now.
 *
 * ESPN's week is a data-layer container that USUALLY holds one Saturday.
 * 2026's Week 1 holds two — games on 8/29 and 9/5, and none at all on the
 * 8/22 its range opens with. Everything downstream inferred the week's
 * Saturday by walking that range and taking the first one, so Week 1's
 * deadline resolved to a Tuesday that had already passed and its
 * official-final to a Sunday before a single game was played. Worse, the
 * publish check only asked whether a game shared the slate's WEEK, which
 * both Saturdays did, so a slate could be built across two of them a week
 * apart.
 *
 * `week_id` stays: it is still ESPN's week, still what the scoreboard and
 * the labels read. What moves is the KEY — a contest gets one slate per
 * SATURDAY, which is the thing actually being played.
 *
 * Two columns ride along rather than earning a second migration over the
 * same table: `exhibition` (the soft-launch practice slate — grades,
 * settles and pays XP, but never reaches season totals) and
 * `celebrity_user_id` (the guest commissioner drawn for a week; it is
 * simultaneously the record and the once-per-season check, since contests
 * are already unique per group and season).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slates', function (Blueprint $table) {
            // Nullable for the backfill; tightened below once every row has
            // one, because a slate without a Saturday is not a partial slate,
            // it is a slate nobody can date.
            $table->date('saturday')->nullable()->after('week_id');

            // A practice week: real picks, real grading, real XP, and no
            // season credit. False is not a default standing in for missing
            // data — every slate is either counted or it is not.
            $table->boolean('exhibition')->default(false)->after('status');

            $table->foreignId('celebrity_user_id')->nullable()->after('exhibition')
                ->constrained('users')->nullOnDelete();
        });

        $this->backfillSaturdays();

        Schema::table('slates', function (Blueprint $table) {
            $table->date('saturday')->nullable(false)->change();

            /*
             * ADD BEFORE DROP, and the order is not cosmetic. `contest_id`'s
             * foreign key has no index of its own: MySQL saw the composite
             * unique leading with `contest_id` at table creation and used it
             * rather than making another. Dropping that unique first orphans
             * the constraint and MySQL refuses outright —
             *   "Cannot drop index ...: needed in a foreign key constraint".
             * The new unique also leads with `contest_id`, so creating it
             * first hands the FK its replacement before the old one goes.
             */
            $table->unique(['contest_id', 'saturday']);
            $table->dropUnique(['contest_id', 'week_id']);
        });
    }

    /**
     * Date every existing slate from its OWN games.
     *
     * The earliest Saturday its games kick on, in Eastern — read from the
     * slate rather than inferred from the week, because inferring from the
     * week is the bug. A slate with no games (an untouched draft) falls back
     * to its week's first Saturday, which is all the information there is.
     */
    private function backfillSaturdays(): void
    {
        $zone = config('cfb.timezone');

        DB::table('slates')->orderBy('id')->each(function (object $slate) use ($zone) {
            $kickoffs = DB::table('slate_games')
                ->join('games', 'games.id', '=', 'slate_games.game_id')
                ->where('slate_games.slate_id', $slate->id)
                ->whereNotNull('games.kickoff_at')
                ->pluck('games.kickoff_at');

            $saturday = $kickoffs
                ->map(fn ($at) => CarbonImmutable::parse($at)->timezone($zone))
                ->filter(fn (CarbonImmutable $at) => $at->dayOfWeek === CarbonImmutable::SATURDAY)
                ->sort()
                ->first()?->toDateString();

            $saturday ??= $this->firstSaturdayOfWeek($slate->week_id, $zone);

            DB::table('slates')->where('id', $slate->id)->update(['saturday' => $saturday]);
        });
    }

    private function firstSaturdayOfWeek(int $weekId, string $zone): string
    {
        $week = DB::table('weeks')->where('id', $weekId)->first();

        $day = CarbonImmutable::parse($week->start_date)->timezone($zone)->startOfDay();
        $end = CarbonImmutable::parse($week->end_date)->timezone($zone);

        while ($day->lessThanOrEqualTo($end)) {
            if ($day->dayOfWeek === CarbonImmutable::SATURDAY) {
                return $day->toDateString();
            }

            $day = $day->addDay();
        }

        // A week holding no Saturday at all cannot host a slate; its own
        // start is the only honest answer, and publish validation refuses it.
        return CarbonImmutable::parse($week->start_date)->timezone($zone)->toDateString();
    }

    public function down(): void
    {
        Schema::table('slates', function (Blueprint $table) {
            // Same ordering rule in reverse, for the same foreign key.
            $table->unique(['contest_id', 'week_id']);
            $table->dropUnique(['contest_id', 'saturday']);

            $table->dropConstrainedForeignId('celebrity_user_id');
            $table->dropColumn(['saturday', 'exhibition']);
        });
    }
};
