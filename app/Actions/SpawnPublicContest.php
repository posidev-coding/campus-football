<?php

namespace App\Actions;

use App\Enums\ContestMode;
use App\Models\Group;
use App\Models\PickemSetting;
use App\Models\Slate;
use App\Models\Week;
use App\Support\Cadence;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Provision the NEXT public room for a mode-SATURDAY: a lobby-kind group
 * with a deterministic name ("Triple Option Open · Sep 12 · Room 2"), the
 * admin's seat cap, ONE contest — and a PUBLISHED slate, because a public
 * room with nothing to pick is a broken promise on the lobby floor.
 *
 * The slate is CLONED from a filled sibling when one exists: every room
 * of a mode-week plays the identical house slate, so "I went 12-3 in the
 * Open" means the same thing in Room 1 and Room 4, and a room spawned
 * Thursday is immune to the market drifting since Room 1 froze. The first
 * room of a mode-week (no sibling) gets the standard slate through the
 * same door the deadline sweep uses.
 *
 * NO commissioner seat: the house runs these rooms, and no copy inside
 * one may ever say "ask your commissioner".
 *
 * Returns null when the week cannot support a valid slate — a thin week
 * spawns nothing rather than an empty room.
 */
class SpawnPublicContest
{
    public function __construct(
        private AutoPublishStandardSlate $standard,
        private PublishSlate $publish,
    ) {}

    public function handle(ContestMode $mode, Week $week, ?CarbonInterface $saturday = null): ?Group
    {
        /*
         * Rooms are named for the SATURDAY they play, not the ESPN week they
         * sit in — 2026's Week 1 holds two, so "Week 1 · Room 1" would name
         * two different cards a fortnight apart. The ordinal counts rooms on
         * that Saturday for that mode, which is what "Room 2" means to
         * somebody looking at the floor.
         */
        $saturday ??= Cadence::saturdayOf($week);

        if ($saturday === null) {
            return null;
        }

        $ordinal = Group::query()
            ->where('kind', Group::KIND_LOBBY)
            ->where('week_id', $week->id)
            ->whereHas('contests', fn ($q) => $q
                ->where('mode', $mode)
                ->whereHas('slates', fn ($s) => $s->where('saturday', $saturday->format('Y-m-d'))))
            ->count() + 1;

        $sibling = $this->publishedSibling($mode, $week, $saturday);

        do {
            $code = Str::upper(Str::random(8));
        } while (Group::where('code', $code)->exists());

        $group = Group::create([
            'name' => "{$mode->label()} Open · {$saturday->format('M j')} · Room {$ordinal}",
            'code' => $code,
            'kind' => Group::KIND_LOBBY,
            'week_id' => $week->id,
            'member_cap' => PickemSetting::lobbyMemberCap(),
        ]);

        $contest = $group->contests()->create([
            'season_year' => (int) $week->season->year,
            'mode' => $mode,
            'settings' => null,
        ]);

        $published = $sibling === null
            ? $this->standard->handle($contest, $week, $saturday)
            : $this->cloneSlate($sibling, $contest->id, $week->id);

        if ($published === null) {
            // No valid slate, no room — leave nothing on the floor.
            $group->delete();

            return null;
        }

        return $group;
    }

    /** The mode-SATURDAY's house slate, from any sibling room that has one. */
    private function publishedSibling(ContestMode $mode, Week $week, CarbonInterface $saturday): ?Slate
    {
        return Slate::query()
            ->where('saturday', $saturday->format('Y-m-d'))
            ->whereIn('status', [Slate::PUBLISHED, Slate::PRELIM])
            ->whereHas('contest', fn ($q) => $q
                ->where('mode', $mode)
                ->whereHas('group', fn ($g) => $g
                    ->where('kind', Group::KIND_LOBBY)
                    ->where('week_id', $week->id)))
            ->with('games')
            ->latest('id')
            ->first();
    }

    /**
     * Copy the sibling's frozen rows verbatim — lines, tiers, provenance,
     * the tiebreaker question — then publish through the same validation
     * every slate passes. Identical by construction, proven at the door.
     */
    private function cloneSlate(Slate $sibling, int $contestId, int $weekId): ?Slate
    {
        $slate = Slate::create([
            'contest_id' => $contestId,
            'week_id' => $weekId,
            // The sibling's Saturday, verbatim like everything else here —
            // an identical house slate is not identical if it is dated to a
            // different card.
            'saturday' => $sibling->saturday,
            'status' => Slate::DRAFT,
        ]);

        $tiebreakerGameId = null;

        foreach ($sibling->games as $slateGame) {
            $copy = $slate->games()->create([
                'game_id' => $slateGame->game_id,
                'tier' => $slateGame->tier,
                'position' => $slateGame->position,
                'spread' => $slateGame->spread,
                'market_spread' => $slateGame->market_spread,
                'favorite_team_id' => $slateGame->favorite_team_id,
                'bear_team_id' => $slateGame->bear_team_id,
                'odds_provider' => $slateGame->odds_provider,
                'odds_captured_at' => $slateGame->odds_captured_at,
            ]);

            if ($sibling->tiebreaker_slate_game_id === $slateGame->id) {
                $tiebreakerGameId = $copy->id;
            }
        }

        $slate->update([
            'tiebreaker_slate_game_id' => $tiebreakerGameId,
            'tiebreaker_metric' => $sibling->tiebreaker_metric,
            'tiebreaker_team_id' => $sibling->tiebreaker_team_id,
            // The sibling's Bear rides along BEFORE publish, whose
            // null-guard then skips reseeding — every room of a mode-week
            // faces the identical Bear, same rule as the lines.
            'bear_theme' => $sibling->bear_theme,
        ]);

        return $this->publish->force($slate->fresh()) === [] ? $slate->fresh() : null;
    }
}
