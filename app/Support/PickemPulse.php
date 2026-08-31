<?php

namespace App\Support;

use App\Models\Contest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Services\Contests\SuggestSlate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * THE PICK'EM PULSE — the viewer's week in one lean read, for surfaces
 * that are NOT the picks area: Home's picks strip, the next-up nudge
 * slot, and the nav presence dot behind them.
 *
 * The same one-query-per-concern shape as My Picks' cards() — groups,
 * contests, slates by Slate::onCard(), one pick aggregate, one entry
 * read — minus everything only the picks area pays for (the wins
 * ledger). Never a per-row query: three groups cost what one does, and
 * a parity test pins it.
 *
 * Memoized in a static per request (the TeamGlance pattern; flushed in
 * tests/Pest.php's beforeEach). Null-shaped throughout: a closed flag
 * or no memberships is an EMPTY answer after at most one query, and a
 * contest with no published slate on the current card simply has no
 * row — callers skip, they never substitute.
 */
class PickemPulse
{
    /**
     * @var array<int, array{
     *     cards: Collection<int, array<string, mixed>>,
     *     awaiting: Collection<int, array{group: Group, contest: Contest}>,
     *     hasGroups: bool,
     *     week: Week|null,
     *     saturday: CarbonImmutable|null,
     * }> keyed by user id
     */
    private static array $state = [];

    /** @var array<int, array<string, mixed>|null> keyed by user id — null is a real answer */
    private static array $nudges = [];

    /**
     * One card per contest with a published slate on the card being
     * played: group, mode, state, made/total, points, entryIn, firstKick.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function cards(User $user): Collection
    {
        return self::state($user)['cards'];
    }

    /**
     * THE LADDER — the single highest-priority nudge for this viewer, or
     * null when there is nothing worth saying. First match wins: picks
     * due → tiebreaker left → the commissioner's build door (only inside
     * the deadline window, feasibility cached — never a scoring pass per
     * render) → live now → the settled payoff → a too-quiet new group →
     * locked-in calm → a seatless reader's way in. The done thing IS the
     * dismissal; nothing here nags a finished entry.
     *
     * Silent for guests' hosts to skip, for unverified readers (the
     * verify callout owns their attention), and behind the closed flag.
     *
     * @return array{key: string, replace: array<string, string>, tone: string, icon: string, cta: string, href: string}|null
     */
    public static function nudge(User $user): ?array
    {
        if (array_key_exists($user->id, self::$nudges)) {
            return self::$nudges[$user->id];
        }

        return self::$nudges[$user->id] = self::resolve($user);
    }

    public static function flush(): void
    {
        self::$state = [];
        self::$nudges = [];
    }

    /** @return array<string, mixed> */
    private static function state(User $user): array
    {
        return self::$state[$user->id] ??= self::build($user);
    }

    /** @return array<string, mixed> */
    private static function build(User $user): array
    {
        // The commit-11 config mirror, never Feature::active() — this can
        // run on every Home render, and Pennant persists per-user rows.
        if (config('cfb.pickem_open') !== true && ! $user->isAdmin()) {
            return self::emptyState();
        }

        $groups = $user->groups()->withCount('memberships')->get();

        if ($groups->isEmpty()) {
            return self::emptyState();
        }

        $contests = Contest::query()
            ->whereIn('group_id', $groups->pluck('id'))
            ->where('season_year', app(CfbCalendar::class)->currentYear())
            ->get()
            ->keyBy('group_id');

        if ($contests->isEmpty()) {
            return self::emptyState(hasGroups: true);
        }

        $weekId = app(CfbCalendar::class)->defaultWeekId($contests->first()->season_year);
        $week = $weekId === null ? null : Week::find($weekId);

        if ($week === null) {
            return self::emptyState(hasGroups: true);
        }

        $saturday = Cadence::activeSaturday($week);

        // The card being played is a SATURDAY — never where('week_id') alone.
        $slates = Slate::query()
            ->whereIn('contest_id', $contests->pluck('id'))
            ->onCard($week)
            ->where('status', '!=', Slate::DRAFT)
            ->with('games.game:id,kickoff_at,status,completed')
            ->get()
            ->keyBy('contest_id');

        /*
         * Groups whose commissioner is this viewer and whose week has no
         * published card yet — the build-door rung's audience. Computed
         * here because cards() only carries contests WITH a slate, and
         * the commissioner with nothing published is exactly who the
         * nudge exists for.
         */
        $awaiting = $groups
            ->filter(fn (Group $group) => ! $group->isRoom()
                && $group->pivot->role === GroupMember::COMMISSIONER
                && $contests->has($group->id)
                && ! $slates->has($contests->get($group->id)->id))
            ->map(fn (Group $group) => ['group' => $group, 'contest' => $contests->get($group->id)])
            ->values();

        if ($slates->isEmpty()) {
            return [
                'cards' => collect(),
                'awaiting' => $awaiting,
                'hasGroups' => true,
                'week' => $week,
                'saturday' => $saturday,
            ];
        }

        $made = Pick::query()
            ->join('slate_games', 'slate_games.id', '=', 'picks.slate_game_id')
            ->whereIn('slate_games.slate_id', $slates->pluck('id'))
            ->where('picks.user_id', $user->id)
            ->groupBy('slate_games.slate_id')
            ->selectRaw('slate_games.slate_id, COUNT(*) AS made, COALESCE(SUM(picks.points), 0) AS pts')
            ->get()
            ->keyBy('slate_id');

        $entries = SlateEntry::query()
            ->whereIn('slate_id', $slates->pluck('id'))
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('slate_id');

        $cards = $groups
            ->map(function (Group $group) use ($contests, $slates, $made, $entries) {
                $contest = $contests->get($group->id);
                $slate = $contest === null ? null : $slates->get($contest->id);

                if ($slate === null) {
                    return null;
                }

                $tally = $made->get($slate->id);
                $entry = $entries->get($slate->id);

                $state = match (true) {
                    $slate->status === Slate::SETTLED => 'final',
                    $slate->status === Slate::PRELIM => 'prelim',
                    $slate->games->contains(fn ($slateGame) => $slateGame->game->hasKickedOff()) => 'live',
                    default => 'upcoming',
                };

                return [
                    'group' => $group,
                    'mode' => $contest->mode,
                    'state' => $state,
                    'made' => (int) ($tally->made ?? 0),
                    'total' => $slate->games->count(),
                    // Signed: a backfired Woodshed Lock is a real −4.
                    'points' => $state === 'final'
                        ? (int) ($entry->final_points ?? 0)
                        : (int) ($tally->pts ?? 0),
                    // The ENTRY, not just the picks — the same derived rule
                    // MakesPicks::entryComplete() and My Picks' entryIn state.
                    'entryIn' => $slate->status === Slate::PUBLISHED
                        && $slate->games->count() > 0
                        && (int) ($tally->made ?? 0) >= $slate->games->count()
                        && ($slate->tiebreaker_slate_game_id === null || $entry?->tiebreaker_total !== null),
                    'hasEntry' => $entry !== null,
                    'won' => (bool) ($entry->won ?? false),
                    'firstKick' => $slate->firstKickoff(),
                ];
            })
            ->filter()
            ->values();

        return [
            'cards' => $cards,
            'awaiting' => $awaiting,
            'hasGroups' => true,
            'week' => $week,
            'saturday' => $saturday,
        ];
    }

    /** @return array<string, mixed> */
    private static function emptyState(bool $hasGroups = false): array
    {
        return [
            'cards' => collect(),
            'awaiting' => collect(),
            'hasGroups' => $hasGroups,
            'week' => null,
            'saturday' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function resolve(User $user): ?array
    {
        // Verification gates every pick'em action, and the verify callout
        // owns an unverified reader's attention — the slot never competes.
        if (! $user->hasVerifiedEmail()) {
            return null;
        }

        if (config('cfb.pickem_open') !== true && ! $user->isAdmin()) {
            return null;
        }

        $state = self::state($user);
        $cards = $state['cards'];

        $byUrgency = fn (Collection $set) => $set
            ->sortBy(fn (array $card) => [
                $card['state'] === 'live' ? 0 : 1,
                // A missing kickoff is missing data — it sorts last.
                $card['firstKick']?->getTimestamp() ?? PHP_INT_MAX,
            ])
            ->first();

        // 1 · PICKS DUE — the most urgent card still taking my picks.
        $due = $byUrgency($cards->filter(fn (array $card) => in_array($card['state'], ['upcoming', 'live'], true)
            && $card['total'] > 0
            && $card['made'] < $card['total']));

        if ($due !== null) {
            $left = $due['total'] - $due['made'];

            return self::entry(
                key: $due['made'] === 0 ? 'picks.next.fresh' : 'picks.next.due',
                replace: [
                    'picks' => $left.' '.Str::plural('pick', $left),
                    'group' => $due['group']->name,
                ],
                tone: 'amber',
                icon: 'check-badge',
                cta: $due['made'] === 0 ? 'Make your picks' : 'Finish your picks',
                href: self::clubhouse($due['group']),
            );
        }

        // 2 · TIEBREAKER LEFT — every game called, the question still open.
        $short = $byUrgency($cards->filter(fn (array $card) => ! $card['entryIn']
            && $card['total'] > 0
            && $card['made'] >= $card['total']
            && in_array($card['state'], ['upcoming', 'live'], true)));

        if ($short !== null) {
            return self::entry(
                key: 'picks.next.tiebreaker',
                replace: [],
                tone: 'amber',
                icon: 'check-badge',
                cta: 'Answer the tiebreaker',
                href: self::clubhouse($short['group']).'?view=slate',
            );
        }

        // 3 · THE BUILD DOOR — commissioners with nothing published, only
        // inside the deadline window, feasibility from one cached count
        // (never a scoring pass at render; NULL leaves the door alone).
        $build = self::buildNudge($state);

        if ($build !== null) {
            return $build;
        }

        // 4 · LIVE NOW — the entry is in and the card is playing.
        $live = $byUrgency($cards->filter(fn (array $card) => $card['state'] === 'live'));

        if ($live !== null) {
            return self::entry(
                key: 'picks.next.live',
                replace: [],
                tone: 'emerald',
                icon: 'trophy',
                cta: 'See where you stand',
                href: self::clubhouse($live['group']),
            );
        }

        // 5 · THE PAYOFF — a settled card I actually played. Expires by
        // construction: the Tuesday turnover takes the slate off the card.
        $final = $cards->filter(fn (array $card) => $card['state'] === 'final' && $card['hasEntry']);
        $settled = $final->firstWhere('won', true) ?? $final->first();

        if ($settled !== null) {
            return self::entry(
                key: $settled['won'] ? 'picks.next.won' : 'picks.next.settled',
                replace: ['group' => $settled['group']->name],
                tone: 'emerald',
                icon: 'trophy',
                cta: 'See your results',
                href: route('pickem.home').'?view=results',
            );
        }

        // 6 · A TOO-QUIET NEW GROUP — the creator's first two weeks. Below
        // the moment rungs on purpose: live games and fresh results beat a
        // standing ask, and the ladder order is what keeps this from ever
        // sitting beside a picks nudge.
        $thin = $state['awaiting']->concat($cards->map(fn (array $card) => ['group' => $card['group'], 'contest' => null]))
            ->map(fn (array $row) => $row['group'])
            ->first(fn (Group $group) => ! $group->isRoom()
                && $group->pivot->role === GroupMember::COMMISSIONER
                && ($group->memberships_count ?? PHP_INT_MAX) <= 2
                && $group->created_at?->gt(now()->subDays(14)) === true);

        if ($thin !== null) {
            return self::entry(
                key: 'picks.next.invite',
                replace: ['group' => $thin->name],
                tone: 'blue',
                icon: 'user-group',
                cta: 'Send the invite',
                href: self::clubhouse($thin).'?view=standings',
            );
        }

        // 7 · LOCKED-IN CALM — everything done, kickoff inside a day.
        $locked = $byUrgency($cards->filter(fn (array $card) => $card['entryIn']
            && $card['state'] === 'upcoming'
            && $card['firstKick'] !== null
            && $card['firstKick']->isFuture()
            && $card['firstKick']->diffInHours(now(), true) < 24));

        if ($locked !== null) {
            return self::entry(
                key: 'picks.next.locked',
                replace: ['time' => $locked['firstKick']->setTimezone(config('cfb.timezone'))->format('D g:ia')],
                tone: 'zinc',
                icon: 'check-badge',
                cta: 'See your slate',
                href: self::clubhouse($locked['group']),
            );
        }

        // 8 · A WAY IN — no seats anywhere, and rooms are open. Counts at
        // you only with a decision attached: zero open rooms says nothing.
        if (! $state['hasGroups']) {
            $open = Lobby::openRoomCount($user);

            if ($open > 0) {
                return self::entry(
                    key: 'picks.next.join',
                    replace: ['rooms' => $open.' public '.Str::plural('room', $open)],
                    tone: 'blue',
                    icon: 'user-group',
                    cta: 'Find a room',
                    href: route('pickem.lobby'),
                );
            }
        }

        return null;
    }

    /**
     * The commissioner's build nudge — evaluated ONLY inside the window
     * between now and the Saturday's slate deadline, with the Saturday's
     * viable-game count cached across renders (five minutes of staleness
     * on a nudge beats the builder's candidate pass on every Home load).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private static function buildNudge(array $state): ?array
    {
        if ($state['awaiting']->isEmpty() || $state['week'] === null || $state['saturday'] === null) {
            return null;
        }

        $deadline = Cadence::slateDeadline($state['saturday']);

        if ($deadline === null || now()->gt($deadline)) {
            return null;
        }

        /** @var array{group: Group, contest: Contest} $first */
        $first = $state['awaiting']->first();

        // Private groups carry no themed filter, so one count answers for
        // all of them — the same once-per-screen rule My Picks follows.
        $viable = Cache::remember(
            'pickem-pulse:viable:'.$state['saturday']->toDateString(),
            300,
            fn () => app(SuggestSlate::class)->viableCount($first['contest'], $state['week'], $state['saturday']),
        );

        foreach ($state['awaiting'] as $row) {
            // NULL/unknown feasibility means leave the door alone — never
            // read "cannot tell" as "no".
            $window = SlateFeasibility::fromCount($viable, $row['contest'], $state['saturday']);

            if (($window['ok'] ?? false) === true) {
                return self::entry(
                    key: 'picks.next.build',
                    replace: ['group' => $row['group']->name],
                    tone: 'amber',
                    icon: 'cog-6-tooth',
                    cta: 'Build the slate',
                    href: route('pickem.build', $row['group']),
                );
            }
        }

        return null;
    }

    private static function clubhouse(Group $group): string
    {
        return $group->isRoom() ? route('pickem.room', $group) : route('pickem.group', $group);
    }

    /**
     * @param  array<string, string>  $replace
     * @return array<string, mixed>
     */
    private static function entry(string $key, array $replace, string $tone, string $icon, string $cta, string $href): array
    {
        return [
            'key' => $key,
            'replace' => $replace,
            'tone' => $tone,
            'icon' => $icon,
            'cta' => $cta,
            'href' => $href,
        ];
    }
}
