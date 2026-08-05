<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follows become an ORDERED list, and the single favorite goes away.
 *
 * `users.favorite_team_id` singled out one team and then made every surface
 * reconcile it with the follow list — the scoreboard had to UNION the favorite
 * into the followed set because a row written before SetFavoriteTeam existed
 * might not be in it, and unfollowing your own favorite had to null the column.
 * A list of up to five teams in an order the user controls says the same thing
 * with none of that.
 *
 * NOTE for anyone grepping "favorite": `game_odds.favorite_team_id` is a
 * DIFFERENT column — the betting favorite, written by SyncOdds — and is
 * deliberately untouched here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_follows', function (Blueprint $table) {
            // No unique index on (user_id, position): a reorder rewrites
            // several rows in one transaction and the intermediate states
            // collide. The index is for reads.
            $table->unsignedTinyInteger('position')->default(0)->after('team_id');
            $table->index(['user_id', 'position']);
        });

        $this->backfillPositions();

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['favorite_team_id']);
            $table->dropColumn('favorite_team_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedMediumInteger('favorite_team_id')->nullable()->after('timezone');
            $table->foreign('favorite_team_id')->references('id')->on('teams')->nullOnDelete();
        });

        // The team at position 1 is what "favorite" meant, so the reverse is
        // lossless for the one fact the column carried.
        DB::table('team_follows')
            ->where('position', 1)
            ->orderBy('user_id')
            ->each(fn ($row) => DB::table('users')
                ->where('id', $row->user_id)
                ->update(['favorite_team_id' => $row->team_id]));

        Schema::table('team_follows', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'position']);
            $table->dropColumn('position');
        });
    }

    /**
     * Preserve today's ordering exactly: the favorite first, then the order
     * they were followed in, with display_name as the deterministic tiebreak —
     * the same ordering `pinnedTeams()` used, so nobody's home page reshuffles
     * on deploy.
     */
    private function backfillPositions(): void
    {
        $favorites = DB::table('users')
            ->whereNotNull('favorite_team_id')
            ->pluck('favorite_team_id', 'id');

        DB::table('team_follows')
            ->select('team_follows.*', 'teams.display_name')
            ->join('teams', 'teams.id', '=', 'team_follows.team_id')
            ->orderBy('team_follows.user_id')
            ->orderBy('team_follows.created_at')
            ->orderBy('teams.display_name')
            ->get()
            ->groupBy('user_id')
            ->each(function ($rows, $userId) use ($favorites) {
                $favorite = $favorites[$userId] ?? null;

                $rows->sortBy(fn ($row) => $row->team_id === $favorite ? 0 : 1)
                    ->values()
                    ->each(fn ($row, $index) => DB::table('team_follows')
                        ->where('id', $row->id)
                        ->update(['position' => $index + 1]));
            });
    }
};
