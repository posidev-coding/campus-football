<?php

namespace App\Support;

use App\Models\Game;
use App\Models\Group;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Services\CfbCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What is actually RUNNING on one Saturday — the inventory, not the health.
 *
 * `OpsReport`, `CoverageReport` and `PickemPreflight` all answer "is this
 * working". None of them answer "what is out there": how many rooms are
 * stocked, which slates are published, whether the practice flag is set,
 * how many people are seated and how many of them have actually picked.
 * That question has no surface at all today, and it is the one an operator
 * asks on a Saturday morning and a session asks before it touches a bug.
 *
 * SHAPE, NEVER PEOPLE. Every user-scoped number here is a COUNT or a
 * DISTRIBUTION — how many entries are empty, never whose. `$names` exists
 * for the one field that is genuinely user-written content, a group's name:
 * true at a terminal (an operator reading their own production is no
 * different from opening the panel), false for any machine-facing skin.
 * `/ops/telemetry` carries a tested no-identity guarantee precisely because
 * its URL ends up in a routine's configuration and a log line, and anything
 * built on top of this must be able to inherit it rather than argue with it.
 *
 * Pilot-scale on purpose: it loads a Saturday's slates with their games and
 * entries rather than assembling aggregates, because a few dozen rows read
 * plainly beats a raw select nobody can check. This is an operator report,
 * never a request path.
 */
class LiveState
{
    public function __construct(private CfbCalendar $calendar) {}

    /**
     * @param  bool  $names  Include group names — a terminal read, not a payload.
     * @return array<string, mixed>
     */
    public function build(?CarbonImmutable $saturday = null, bool $names = true): array
    {
        $saturday ??= Cadence::currentSaturday();

        $window = $this->window($saturday);

        return [
            'generated_at' => now()->toIso8601String(),
            'saturday' => $saturday->toDateString(),
            'season' => [
                'current_year' => $this->calendar->currentYear(),
                'phase' => $this->calendar->phase()->value,
                'week' => $this->calendar->week()?->number,
            ],
            'clock' => [
                'deadline' => Cadence::slateDeadline($saturday)?->toIso8601String(),
                'official_final' => Cadence::officialFinal($saturday)?->toIso8601String(),
                'first_kickoff' => $window['first']?->toIso8601String(),
            ],
            'games' => [
                'in_window' => $window['games']->count(),
                'lined' => $window['games']->where('odds_count', '>', 0)->count(),
                'kicked' => $window['games']->filter(fn (Game $game): bool => $game->hasKickedOff())->count(),
                'final' => $window['games']->where('completed', true)->count(),
            ],
            'contests' => $this->contests($saturday, $names),
            'groups' => $this->groups(),
            'people' => $this->people(),
        ];
    }

    /**
     * The Saturday card, read the only way it can be.
     *
     * The window is ET midnight Saturday to ET noon Sunday, because a 22:00
     * kickoff is 02:00 UTC the next day — matching the UTC date drops the
     * whole night. `inSlateWindow()` then applies the noon floor in PHP,
     * where it has to live: the ET boundary moves under DST and cannot be
     * asked in SQL.
     *
     * @return array{games: Collection<int, Game>, first: ?CarbonImmutable}
     */
    private function window(CarbonImmutable $saturday): array
    {
        $games = Game::query()
            ->withCount('odds')
            ->whereBetween('kickoff_at', [
                $saturday->startOfDay()->utc(),
                $saturday->addDay()->setTime(12, 0)->utc(),
            ])
            ->get()
            ->filter(fn (Game $game): bool => $game->inSlateWindow())
            ->values();

        $first = $games->pluck('kickoff_at')->filter()->min();

        return [
            'games' => $games,
            'first' => $first === null ? null : CarbonImmutable::parse($first),
        ];
    }

    /**
     * One row per contest with a slate on this Saturday.
     *
     * `expected` comes from the contest's own engine rather than the mode's
     * headline number, because the lobby catalog shrinks a room's slate to
     * what the week can actually field — a Week 0 Shotgun room is eight
     * games and is not therefore broken.
     *
     * @return list<array<string, mixed>>
     */
    private function contests(CarbonImmutable $saturday, bool $names): array
    {
        $slates = Slate::query()
            ->where('saturday', $saturday->toDateString())
            ->with(['contest.group', 'games.game', 'entries'])
            ->get();

        if ($slates->isEmpty()) {
            return [];
        }

        $picks = $this->picksBySlate($slates->modelKeys());
        $late = $this->lateBySlate($slates);
        $lift = $this->liftBySlate($slates);

        return $slates->map(function (Slate $slate) use ($names, $picks, $late, $lift): array {
            $contest = $slate->contest;
            $group = $contest?->group;
            $made = $picks[$slate->id] ?? [];
            $games = $slate->games->count();

            return [
                'slate_id' => $slate->id,
                'contest_id' => $contest?->id,
                'group_id' => $group?->id,
                // User-written content, so it is the one field the machine
                // skin drops. Everything else here is structure.
                'group' => $names ? $group?->name : null,
                'kind' => $group?->kind,
                'flavor' => $group?->flavor,
                'mode' => $contest?->mode->value,
                'mode_label' => $contest?->mode->label(),
                'status' => $slate->status,
                'exhibition' => $slate->exhibition,
                'expected_games' => $contest?->mode->engine($contest->settings)->slateSize(),
                'games' => $games,
                'lined' => $slate->games->filter(fn (SlateGame $g): bool => $g->spread !== null)->count(),
                'tiered' => $slate->games->filter(fn (SlateGame $g): bool => $g->tier !== null)->count(),
                'tiebreaker' => $slate->tiebreaker_slate_game_id !== null,
                'entries' => $slate->entries->count(),
                // The distribution, never the people. "Nine entries have no
                // picks in them" is the whole finding; which nine is not.
                'picks_made' => array_sum($made),
                'picks_possible' => $slate->entries->count() * $games,
                'entries_complete' => $games === 0
                    ? 0
                    : count(array_filter($made, fn (int $n): bool => $n >= $games)),
                'entries_empty' => $slate->entries->count() - count($made),
                'first_kickoff' => $slate->firstKickoff()?->toIso8601String(),
                'published_at' => $slate->published_at?->toIso8601String(),
                'picks_reminded_at' => $slate->picks_reminded_at?->toIso8601String(),
                'last_call_sent_at' => $slate->last_call_sent_at?->toIso8601String(),
                'settled_at' => $slate->settled_at?->toIso8601String(),
                'results_announced_at' => $slate->results_announced_at?->toIso8601String(),
                /*
                 * The two rates phase 4 left null and read through `??`. They
                 * are NULL WITHOUT A DENOMINATOR, never 0: "nobody picked
                 * late" and "we could not tell" are different findings, and
                 * one of them is the only analytics signal that can earn
                 * `high`. The MIN_ENTRIES floor is applied one layer up, in
                 * AnalyticsCatalog, so this stays the raw measurement.
                 */
                'late_share' => $late[$slate->id] ?? null,
                'reminder_lift' => $lift[$slate->id] ?? null,
            ];
        })->all();
    }

    /**
     * Picks per slate, per entrant, in ONE query for the whole board.
     *
     * `picks` hangs off `slate_games`, not off `slate_entries`, so the slate
     * is only reachable through the join.
     *
     * @param  list<int>  $slateIds
     * @return array<int, array<int, int>> slate id => user id => picks made
     */
    private function picksBySlate(array $slateIds): array
    {
        $rows = DB::table('picks')
            ->join('slate_games', 'slate_games.id', '=', 'picks.slate_game_id')
            ->whereIn('slate_games.slate_id', $slateIds)
            ->groupBy('slate_games.slate_id', 'picks.user_id')
            ->get([
                'slate_games.slate_id as slate_id',
                'picks.user_id as user_id',
                DB::raw('count(*) as made'),
            ]);

        $made = [];

        foreach ($rows as $row) {
            $made[(int) $row->slate_id][(int) $row->user_id] = (int) $row->made;
        }

        return $made;
    }

    /**
     * The share of each slate's picks made in the last-call window.
     *
     * `updated_at`, not `created_at`: changing your mind at 11:58 is a late
     * pick, and the question this answers is whether people are deciding
     * under the wire — which is what makes a reminder worth sending.
     *
     * NULL WITH NO PICKS. A slate nobody picked has no late share; reporting
     * 0% would say people picked early.
     *
     * @param  Collection<int, Slate>  $slates
     * @return array<int, float|null>
     */
    private function lateBySlate(Collection $slates): array
    {
        $out = [];

        foreach ($slates as $slate) {
            $kickoff = $slate->firstKickoff();

            if ($kickoff === null) {
                continue;
            }

            $counts = DB::table('picks')
                ->join('slate_games', 'slate_games.id', '=', 'picks.slate_game_id')
                ->where('slate_games.slate_id', $slate->id)
                ->selectRaw('count(*) as total, sum(picks.updated_at >= ? and picks.updated_at <= ?) as late', [
                    CarbonImmutable::parse($kickoff)->subMinutes(Cadence::LAST_CALL_MINUTES),
                    CarbonImmutable::parse($kickoff),
                ])
                ->first();

            $total = (int) ($counts->total ?? 0);

            $out[$slate->id] = $total === 0 ? null : round((int) $counts->late / $total, 3);
        }

        return $out;
    }

    /**
     * Did the reminder wave move anybody?
     *
     * Entries created between `picks_reminded_at` and first kickoff, over the
     * people who COULD have been moved: members at the moment of the wave who
     * had no entry yet.
     *
     * THE DENOMINATOR ROOTS IN `group_members`, never in `slate_entries` —
     * the standing rule from the weekly loop, and the whole point here. An
     * entry row is created lazily on a member's FIRST pick, so somebody who
     * has picked nothing has no entry at all and is invisible to a query
     * rooted in entries. That person IS the reminder's audience; rooting in
     * entries would measure the lift only on people who had already played.
     *
     * NULL with no wave sent, and null when nobody was left to move.
     *
     * @param  Collection<int, Slate>  $slates
     * @return array<int, float|null>
     */
    private function liftBySlate(Collection $slates): array
    {
        $out = [];

        foreach ($slates as $slate) {
            $reminded = $slate->picks_reminded_at;
            $kickoff = $slate->firstKickoff();
            $groupId = $slate->contest?->group?->id;

            if ($reminded === null || $kickoff === null || $groupId === null) {
                continue;
            }

            $eligible = DB::table('group_members')
                ->where('group_id', $groupId)
                ->where('created_at', '<=', $reminded)
                ->whereNotIn('user_id', DB::table('slate_entries')
                    ->where('slate_id', $slate->id)
                    ->where('created_at', '<=', $reminded)
                    ->select('user_id'))
                ->count();

            if ($eligible === 0) {
                $out[$slate->id] = null;

                continue;
            }

            $moved = DB::table('slate_entries')
                ->where('slate_id', $slate->id)
                ->where('created_at', '>', $reminded)
                ->where('created_at', '<=', $kickoff)
                ->count();

            $out[$slate->id] = round($moved / $eligible, 3);
        }

        return $out;
    }

    /**
     * Public so the admin dashboard's widgets read the same counts the
     * telemetry payload does, rather than a second implementation that can
     * drift from it. `build()`'s payload shape is unchanged.
     *
     * @return array<string, mixed>
     */
    public function groups(): array
    {
        $rows = Group::query()
            ->groupBy('kind')
            ->get(['kind', DB::raw('count(*) as total'), DB::raw('sum(filled_at is not null) as filled')]);

        $byKind = [];

        foreach ($rows as $row) {
            $byKind[$row->kind] = ['total' => (int) $row->total, 'filled' => (int) $row->filled];
        }

        return [
            'by_kind' => $byKind,
            'by_flavor' => Group::query()
                ->whereNotNull('flavor')
                ->groupBy('flavor')
                ->pluck(DB::raw('count(*)'), 'flavor')
                ->map(fn ($count): int => (int) $count)
                ->all(),
        ];
    }

    /**
     * Counts only, at every register. There is no `$names` branch here on
     * purpose — no shape of this report ever names a person.
     *
     * Public for the same reason `groups()` is: the admin funnel widget reads
     * these rather than counting users a second time.
     *
     * @return array<string, int>
     */
    public function people(): array
    {
        return [
            'users' => User::query()->count(),
            'verified' => User::query()->whereNotNull('email_verified_at')->count(),
            'admins' => User::query()->where('admin', true)->count(),
            'onboarded' => User::query()->whereNotNull('onboarded_at')->count(),
            // Devices, then people: one reader can grant push on a phone and
            // a laptop, and "can we reach anybody" is the people number.
            'push_devices' => DB::table('push_subscriptions')->count(),
            'push_people' => User::query()->whereHas('pushSubscriptions')->count(),
        ];
    }
}
