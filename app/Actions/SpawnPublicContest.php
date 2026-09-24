<?php

namespace App\Actions;

use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Models\Contest;
use App\Models\Game;
use App\Models\Group;
use App\Models\PickemSetting;
use App\Models\Slate;
use App\Models\Week;
use App\Services\Contests\SuggestSlate;
use App\Support\Cadence;
use App\Support\LobbyCatalog;
use App\Support\RoomNames;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Provision the NEXT public room of one shape — a (mode, flavor) pair on
 * one SATURDAY: a lobby-kind group named from the lobby's pools, the
 * shape's seat cap, ONE contest stamped with the shape's settings — and a
 * PUBLISHED slate, because a public room with nothing to pick is a broken
 * promise in the lobby.
 *
 * The slate is CLONED from a published sibling of the SAME shape when one
 * exists: every room of a shape-Saturday plays the identical house slate,
 * so "I went 12-3 in Hail Mary" means the same thing in Hail Mary and
 * Flea Flicker, and a room spawned Thursday is immune to the market
 * drifting since the first froze. Contest settings are copied verbatim
 * with the slate — a poll landing mid-week must not resize a cloned card.
 * The first room of a shape resolves its settings fresh from the catalog,
 * which is also the feasibility gate: an impossible Saturday (Week 0
 * cannot seat a fifteen-game mode) spawns NOTHING rather than a thin
 * slate that lies.
 *
 * NO commissioner seat: the house runs these rooms, and no copy inside
 * one may ever say "ask your commissioner".
 */
class SpawnPublicContest
{
    public function __construct(
        private AutoPublishStandardSlate $standard,
        private PublishSlate $publish,
        private SuggestSlate $suggest,
    ) {}

    public function handle(ContestMode $mode, Week $week, ?CarbonInterface $saturday = null, ?LobbyFlavor $flavor = null): ?Group
    {
        $saturday ??= Cadence::saturdayOf($week);

        if ($saturday === null) {
            return null;
        }

        $sibling = $this->publishedSibling($mode, $week, $saturday, $flavor);

        if ($sibling !== null) {
            $settings = $sibling->contest->settings;
        } else {
            $resolved = LobbyCatalog::resolve($mode, $flavor, $week, $saturday);

            if ($resolved === null) {
                Log::info('Lobby room infeasible for its Saturday; not spawned.', [
                    'mode' => $mode->value,
                    'flavor' => $flavor?->value,
                    'saturday' => $saturday->format('Y-m-d'),
                ]);

                return null;
            }

            $settings = $resolved['settings'];
        }

        $firstKickoff = $this->firstKickoff($sibling, $mode, $settings, $week, $saturday);

        if ($firstKickoff === null || $firstKickoff->lessThanOrEqualTo(now()->addMinutes(Cadence::ROOM_RUNWAY_MINUTES))) {
            Log::info('Lobby room too close to its first kickoff; not spawned.', [
                'mode' => $mode->value,
                'flavor' => $flavor?->value,
                'saturday' => $saturday->format('Y-m-d'),
                'first_kickoff' => $firstKickoff?->toIso8601String(),
            ]);

            return null;
        }

        do {
            $code = Str::upper(Str::random(8));
        } while (Group::where('code', $code)->exists());

        $group = Group::create([
            'name' => $this->name($mode, $flavor, $week, $saturday),
            'code' => $code,
            'kind' => Group::KIND_LOBBY,
            'flavor' => $flavor?->value,
            'week_id' => $week->id,
            'member_cap' => $flavor?->cap() ?? PickemSetting::lobbyMemberCap(),
        ]);

        $contest = $group->contests()->create([
            'season_year' => (int) $week->season->year,
            'mode' => $mode,
            'settings' => $settings,
        ]);

        $published = $sibling === null
            ? $this->standard->handle($contest, $week, $saturday)
            : $this->cloneSlate($sibling, $contest->id, $week->id);

        if ($published === null) {
            // No valid slate, no room — leave nothing in the lobby.
            $group->delete();

            return null;
        }

        return $group;
    }

    /**
     * The first kickoff of the card this room WOULD deal, asked before
     * anything is created.
     *
     * THE RUNWAY GATE lives here rather than in the sweep's hasOpenRoom(),
     * whose docblock says why it must not learn the under-way condition. Here
     * it covers both doors: the hourly sweep, and spawn-on-fill when the last
     * seat of a room goes after the card is already close. A room closes to
     * new seats at its first kickoff, so one opened inside
     * Cadence::ROOM_RUNWAY_MINUTES sells for minutes and then collects a
     * reminder cadence it can never use. ACC Action opened 45 minutes out on
     * 2026-09-19 and drew nobody (CFB-98). Skipping is the right outcome: a
     * shape that becomes seatable at noon on a Saturday does not get a room.
     *
     * A clone deals its sibling's card exactly, so that card is the answer.
     * A first room asks the same suggestion the standard builder is about to
     * run, on an unsaved contest the way LobbyCatalog::resolve() probes. A
     * new room has no members, so the follow bonus cannot differ between the
     * two. Null is no card to measure, and the caller skips it.
     *
     * @param  array<string, mixed>|null  $settings
     */
    private function firstKickoff(?Slate $sibling, ContestMode $mode, ?array $settings, Week $week, CarbonInterface $saturday): ?CarbonInterface
    {
        if ($sibling !== null) {
            return $sibling->loadMissing('games.game:id,kickoff_at')->firstKickoff();
        }

        $gameIds = array_column(
            $this->suggest->for((new Contest)->forceFill(['mode' => $mode, 'settings' => $settings]), $week, $saturday),
            'game_id',
        );

        return Game::query()->whereIn('id', $gameIds)->get(['id', 'kickoff_at'])->min('kickoff_at');
    }

    /**
     * Rooms wear NAMES, not serials: standard rooms draw from their mode's
     * pool, specialties carry their marquee, and either takes a Roman
     * numeral when this Saturday's lobby already used the name.
     */
    private function name(ContestMode $mode, ?LobbyFlavor $flavor, Week $week, CarbonInterface $saturday): string
    {
        $taken = Group::query()
            ->where('kind', Group::KIND_LOBBY)
            ->where('week_id', $week->id)
            ->whereHas('contests.slates', fn ($s) => $s->where('saturday', $saturday->format('Y-m-d')))
            ->pluck('name');

        return $flavor === null
            ? RoomNames::next($mode, $taken)
            : RoomNames::successor($flavor->label(), $taken);
    }

    /**
     * The shape-Saturday's house slate, from any sibling room of the SAME
     * flavor that has one. The flavor condition is load-bearing: a flash
     * card and the standard slate share a mode, and without it they would
     * cross-clone each other's slates.
     */
    private function publishedSibling(ContestMode $mode, Week $week, CarbonInterface $saturday, ?LobbyFlavor $flavor): ?Slate
    {
        return Slate::query()
            ->where('saturday', $saturday->format('Y-m-d'))
            ->whereIn('status', [Slate::PUBLISHED, Slate::PRELIM])
            ->whereHas('contest', fn ($q) => $q
                ->where('mode', $mode)
                ->whereHas('group', fn ($g) => $g
                    ->where('kind', Group::KIND_LOBBY)
                    ->where('week_id', $week->id)
                    ->where('flavor', $flavor?->value)))
            ->with(['games', 'contest:id,settings'])
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
            // null-guard then skips reseeding — every room of a shape-week
            // faces the identical Bear, same rule as the lines.
            'bear_theme' => $sibling->bear_theme,
        ]);

        return $this->publish->force($slate->fresh()) === [] ? $slate->fresh() : null;
    }
}
