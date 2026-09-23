<?php

namespace App\Actions;

use App\Enums\ContestMode;
use App\Exceptions\MergeBlocked;
use App\Models\Contest;
use App\Models\ConversationPost;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use App\Models\WalletEntry;
use App\Notifications\GroupsMerged;
use App\Services\CfbCalendar;
use App\Support\Cadence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fold private groups into one that survives — the house's lever, pulled
 * from `pickem:merge-groups`, never from a screen.
 *
 * Every mover takes a seat through JoinGroup, the one door that seats
 * anybody. The survivor may switch modes through ChangeGroupMode::pivot(),
 * the one write that changes a mode; the house is not bound by the
 * commissioner's once-a-season rule, but it stamps it, so the survivor's
 * mode holds for the rest of the season. A FRESH ledger marks the
 * survivor's settled weeks as exhibitions, which is how the season table
 * starts everybody at 0–0 — the one deliberate rewrite of a stamp that is
 * otherwise written once, at publish. The folded groups are then deleted
 * the way LeaveGroup deletes an empty one, plus the two things no foreign
 * key cascades: their Talk posts and their icon file.
 *
 * Nothing moves while any week is in flight anywhere in the set. A
 * published week graded under a different mode, or deleted with its picks
 * unsettled, is a week nobody can get back — so the guard is read for the
 * plan, and read again inside the transaction under a lock, because the
 * hourly publish and settle sweeps keep their own clock.
 *
 * Every member of the survivor hears about it (GroupsMerged). The note is
 * the action's side effect, never a checkbox, for ChangeGroupMode's reason:
 * a group that learns its rules changed from the standings has been let
 * down.
 */
class MergeGroups
{
    public function __construct(
        private CfbCalendar $calendar,
        private JoinGroup $join,
        private ChangeGroupMode $modes,
    ) {}

    /**
     * Everything the merge would do, and every reason it must not — READ
     * ONLY. The dry run prints exactly this, and handle() acts on it.
     *
     * @param  Collection<int, Group>  $from
     * @return array{
     *     blockers: list<string>,
     *     warnings: list<string>,
     *     into: array{id: int, name: string, code: string, members: int, commissioners: list<string>, contest_id: int|null, mode: string|null, mode_changed_at: string|null},
     *     from: list<array{id: int, name: string, code: string, members: int, commissioners: list<string>, contests: int, slates: int, entries: int, picks: int, posts: int, invites: int, icon: bool}>,
     *     movers: list<int>,
     *     overlap: int,
     *     unverified: int,
     *     first_group_xp: int,
     *     history_lost: int,
     *     former_members: int,
     *     pivot: array{from: string, to: string, overrides: bool, drafts: int}|null,
     *     fresh_start: list<int>,
     *     recipients: list<array{user_id: int, name: string, variant: string, former: list<string>, ran: list<string>, runs_it: bool, verified: bool, weeks_before: int, weeks_after: int}>,
     * }
     */
    public function plan(Group $into, Collection $from, ?ContestMode $mode, bool $freshStart): array
    {
        $from = $from->values();
        $fromIds = $from->pluck('id')->all();
        $groups = collect([$into])->merge($from)->unique('id')->keyBy('id');

        $contest = $into->contests()->where('season_year', $this->calendar->currentYear())->first();

        $seats = GroupMember::query()
            ->whereIn('group_id', $groups->keys())
            ->with('user:id,first_name,last_name,handle,email_verified_at')
            ->get();

        $stayers = $seats->where('group_id', $into->id)->pluck('user_id')->unique();
        $sourceSeats = $seats->whereIn('group_id', $fromIds);
        $movers = $sourceSeats->pluck('user_id')->unique()->diff($stayers)->values();

        $blockers = $this->blockers($into, $from, $contest, $mode, $seats);

        $warnings = [];
        $pivot = null;

        if ($contest !== null && $mode !== null && $mode !== $contest->mode) {
            $pivot = [
                'from' => $contest->mode->label(),
                'to' => $mode->label(),
                'overrides' => $contest->mode_changed_at !== null,
                'drafts' => $contest->slates()->where('status', Slate::DRAFT)->count(),
            ];

            if ($pivot['overrides']) {
                $warnings[] = "{$into->name} already used its mode change this season; the house overrides it.";
            }
        }

        if ($contest !== null && $mode !== null && $mode === $contest->mode && $contest->mode_changed_at === null) {
            $warnings[] = "{$into->name} already plays {$mode->label()}, so nothing is stamped — its commissioner can still switch modes once this season.";
        }

        $freshSlates = ($freshStart && $contest !== null)
            ? $contest->slates()->where('status', Slate::SETTLED)->where('exhibition', false)->orderBy('saturday')->pluck('id')->all()
            : [];

        // Everyone who ever held an entry in a folded group loses those
        // weeks from History — including people who have since left, who
        // are in no group here and so hear nothing about it.
        $played = SlateEntry::query()
            ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
            ->join('contests', 'contests.id', '=', 'slates.contest_id')
            ->whereIn('contests.group_id', $fromIds)
            ->distinct()
            ->pluck('slate_entries.user_id');

        $weeks = $this->weeksPlayed($played->merge($stayers)->merge($movers)->unique()->values(), $fromIds);

        $firstSeat = $movers->isEmpty() ? collect() : WalletEntry::query()
            ->whereIn('user_id', $movers)
            ->where('key', GrantWalletEntry::REASON_FIRST_GROUP)
            ->pluck('user_id');

        $users = $seats->pluck('user')->filter()->keyBy('id');

        return [
            'blockers' => $blockers,
            'warnings' => $warnings,
            'into' => [
                'id' => $into->id,
                'name' => $into->name,
                'code' => $into->code,
                'members' => $stayers->count(),
                'commissioners' => $this->commissioners($seats->where('group_id', $into->id)),
                'contest_id' => $contest?->id,
                'mode' => $contest?->mode->label(),
                'mode_changed_at' => $contest?->mode_changed_at?->toDateTimeString(),
            ],
            'from' => $this->sources($from, $seats),
            'movers' => $movers->all(),
            'overlap' => $sourceSeats->pluck('user_id')->unique()->intersect($stayers)->count(),
            'unverified' => $movers->filter(fn (int $id) => $users->get($id)?->email_verified_at === null)->count(),
            'first_group_xp' => $movers
                ->filter(fn (int $id) => $users->get($id)?->email_verified_at !== null)
                ->diff($firstSeat)
                ->count(),
            'history_lost' => $played->count(),
            'former_members' => $played->diff($seats->pluck('user_id'))->count(),
            'pivot' => $pivot,
            'fresh_start' => $freshSlates,
            'recipients' => $stayers->merge($movers)
                ->map(function (int $userId) use ($into, $from, $seats, $users, $stayers, $weeks): array {
                    $mine = $seats->where('user_id', $userId);
                    $retired = $from->filter(fn (Group $group) => $mine->contains('group_id', $group->id));
                    $user = $users->get($userId);

                    return [
                        'user_id' => $userId,
                        'name' => $this->label($user),
                        'variant' => $stayers->contains($userId) ? GroupsMerged::STAYED : GroupsMerged::MOVED,
                        'former' => $retired->pluck('name')->values()->all(),
                        'ran' => $retired
                            ->filter(fn (Group $group) => $mine->where('group_id', $group->id)->contains('role', GroupMember::COMMISSIONER))
                            ->pluck('name')
                            ->values()
                            ->all(),
                        'runs_it' => $mine->where('group_id', $into->id)->contains('role', GroupMember::COMMISSIONER),
                        'verified' => $user?->email_verified_at !== null,
                        'weeks_before' => $weeks[$userId]['before'] ?? 0,
                        'weeks_after' => $weeks[$userId]['after'] ?? 0,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * Do it: seat, pivot, re-ledger, delete — one transaction — then tell
     * everybody.
     *
     * @param  Collection<int, Group>  $from
     * @return array{into: int, retired: list<array{id: int, code: string, name: string}>, seated: list<int>, pivot: array{from: string, to: string, overrides: bool, drafts: int}|null, exhibition_slates: list<int>, notified: array{total: int, mail: int, inbox: int, push: int}}
     *
     * @throws MergeBlocked
     */
    public function handle(Group $into, Collection $from, ?ContestMode $mode, bool $freshStart): array
    {
        $from = $from->values();
        $plan = $this->plan($into, $from, $mode, $freshStart);

        if ($plan['blockers'] !== []) {
            throw MergeBlocked::because($plan['blockers']);
        }

        $contest = Contest::query()->findOrFail($plan['into']['contest_id']);
        $movers = User::query()->whereIn('id', $plan['movers'])->get();
        $icons = $from->pluck('icon')->filter()->values()->all();

        DB::transaction(function () use ($into, $from, $mode, $plan, $contest, $movers): void {
            // Asked again, under a lock: the plan was read outside this
            // transaction, and a sweep can publish or settle in between.
            $late = $this->inFlight(collect([$into])->merge($from), lock: true);

            if ($late !== []) {
                throw MergeBlocked::because($late);
            }

            foreach ($movers as $mover) {
                $this->join->handle($mover, $into);
            }

            if ($plan['pivot'] !== null) {
                $this->modes->pivot($contest, $mode);
            }

            if ($plan['fresh_start'] !== []) {
                Slate::query()->whereKey($plan['fresh_start'])->update(['exhibition' => true]);
            }

            // slates ⇄ slate_games point at each other (the featured game), so
            // break the cycle before the cascade walks it — the schema's own
            // down() does the same, and InnoDB can refuse a cascade that
            // loops back to a table it is already deleting from.
            Slate::query()
                ->whereIn('contest_id', Contest::query()->whereIn('group_id', $from->pluck('id'))->select('id'))
                ->update(['tiebreaker_slate_game_id' => null]);

            foreach ($from as $group) {
                // Talk posts hang off a morph with no foreign key, so the
                // cascade below would leave a private thread behind with no
                // page left that shows it.
                ConversationPost::query()
                    ->where('topic_type', 'group')
                    ->where('topic_id', $group->id)
                    ->delete();

                $group->delete();
            }
        });

        $this->forgetIcons($icons);

        return [
            'into' => $into->id,
            'retired' => $from->map(fn (Group $group) => ['id' => $group->id, 'code' => $group->code, 'name' => $group->name])->all(),
            'seated' => $plan['movers'],
            'pivot' => $plan['pivot'],
            'exhibition_slates' => $plan['fresh_start'],
            'notified' => $this->announce($into, $contest->fresh(), $plan, $freshStart),
        ];
    }

    /**
     * Every reason to refuse, all at once.
     *
     * @param  Collection<int, Group>  $from
     * @param  Collection<int, GroupMember>  $seats
     * @return list<string>
     */
    private function blockers(Group $into, Collection $from, ?Contest $contest, ?ContestMode $mode, Collection $seats): array
    {
        $blockers = [];

        if ($from->isEmpty()) {
            $blockers[] = 'Name at least one group to fold in.';
        }

        foreach (collect([$into])->merge($from)->unique('id') as $group) {
            if ($group->isLobby()) {
                $blockers[] = "{$group->name} ({$group->code}) is a public contest, not a private group.";
            }
        }

        if ($from->contains(fn (Group $group) => $group->is($into))) {
            $blockers[] = "{$into->name} ({$into->code}) cannot fold into itself.";
        }

        foreach ($from->countBy('id')->filter(fn (int $times) => $times > 1)->keys() as $id) {
            $group = $from->firstWhere('id', $id);
            $blockers[] = "{$group->name} ({$group->code}) is listed more than once.";
        }

        if ($contest === null) {
            $blockers[] = "{$into->name} ({$into->code}) has no contest this season.";
        }

        if (! $seats->where('group_id', $into->id)->contains('role', GroupMember::COMMISSIONER)) {
            $blockers[] = "{$into->name} ({$into->code}) has no commissioner to build its slates.";
        }

        if ($mode !== null && ! $mode->available()) {
            $blockers[] = "The {$mode->value} mode is not available to field.";
        }

        return [...$blockers, ...$this->inFlight(collect([$into])->merge($from))];
    }

    /**
     * Every published or preliminary slate in the set, named.
     *
     * With `lock`, the set's slate rows are read FOR UPDATE, which also
     * holds off a sweep inserting a new one for these contests until the
     * merge commits.
     *
     * @param  Collection<int, Group>  $groups
     * @return list<string>
     */
    private function inFlight(Collection $groups, bool $lock = false): array
    {
        $groups = $groups->unique('id')->keyBy('id');
        $contests = Contest::query()->whereIn('group_id', $groups->keys())->pluck('group_id', 'id');

        return Slate::query()
            ->whereIn('contest_id', $contests->keys())
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'contest_id', 'status', 'saturday'])
            ->filter(fn (Slate $slate) => in_array($slate->status, [Slate::PUBLISHED, Slate::PRELIM], true))
            ->map(function (Slate $slate) use ($groups, $contests): string {
                $group = $groups->get($contests->get($slate->contest_id));

                return "{$group->name} ({$group->code}) has a {$slate->status} slate for {$slate->saturday->toDateString()} still in flight.";
            })
            ->values()
            ->all();
    }

    /**
     * Distinct Saturdays each person has held an entry on, before and after
     * the folded groups go — the count MakePick::milestones() pays the
     * weeks-entered Tallboys from.
     *
     * @param  Collection<int, int>  $userIds
     * @param  list<int>  $fromIds
     * @return array<int, array{before: int, after: int}>
     */
    private function weeksPlayed(Collection $userIds, array $fromIds): array
    {
        if ($userIds->isEmpty()) {
            return [];
        }

        $count = fn (bool $keepFolded) => SlateEntry::query()
            ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
            ->join('contests', 'contests.id', '=', 'slates.contest_id')
            ->whereIn('slate_entries.user_id', $userIds)
            ->when(! $keepFolded, fn ($query) => $query->whereNotIn('contests.group_id', $fromIds))
            ->groupBy('slate_entries.user_id')
            ->selectRaw('slate_entries.user_id, COUNT(DISTINCT slates.saturday) AS weeks')
            ->pluck('weeks', 'user_id');

        $before = $count(true);
        $after = $count(false);

        return $userIds
            ->mapWithKeys(fn (int $id) => [$id => [
                'before' => (int) ($before[$id] ?? 0),
                'after' => (int) ($after[$id] ?? 0),
            ]])
            ->all();
    }

    /**
     * What deleting each folded group takes with it — one query per
     * concern, grouped by group, never a query per group.
     *
     * @param  Collection<int, Group>  $from
     * @param  Collection<int, GroupMember>  $seats
     * @return list<array{id: int, name: string, code: string, members: int, commissioners: list<string>, contests: int, slates: int, entries: int, picks: int, posts: int, invites: int, icon: bool}>
     */
    private function sources(Collection $from, Collection $seats): array
    {
        $ids = $from->pluck('id')->all();

        $contests = Contest::query()
            ->whereIn('group_id', $ids)
            ->groupBy('group_id')
            ->selectRaw('group_id, COUNT(*) AS n')
            ->pluck('n', 'group_id');

        $slates = Slate::query()
            ->join('contests', 'contests.id', '=', 'slates.contest_id')
            ->whereIn('contests.group_id', $ids)
            ->groupBy('contests.group_id')
            ->selectRaw('contests.group_id, COUNT(*) AS n')
            ->pluck('n', 'group_id');

        $entries = SlateEntry::query()
            ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
            ->join('contests', 'contests.id', '=', 'slates.contest_id')
            ->whereIn('contests.group_id', $ids)
            ->groupBy('contests.group_id')
            ->selectRaw('contests.group_id, COUNT(*) AS n')
            ->pluck('n', 'group_id');

        $picks = Pick::query()
            ->join('slate_games', 'slate_games.id', '=', 'picks.slate_game_id')
            ->join('slates', 'slates.id', '=', 'slate_games.slate_id')
            ->join('contests', 'contests.id', '=', 'slates.contest_id')
            ->whereIn('contests.group_id', $ids)
            ->groupBy('contests.group_id')
            ->selectRaw('contests.group_id, COUNT(*) AS n')
            ->pluck('n', 'group_id');

        $posts = ConversationPost::query()
            ->where('topic_type', 'group')
            ->whereIn('topic_id', $ids)
            ->groupBy('topic_id')
            ->selectRaw('topic_id, COUNT(*) AS n')
            ->pluck('n', 'topic_id');

        $invites = GroupInvite::query()
            ->whereIn('group_id', $ids)
            ->groupBy('group_id')
            ->selectRaw('group_id, COUNT(*) AS n')
            ->pluck('n', 'group_id');

        return $from
            ->unique('id')
            ->map(fn (Group $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'code' => $group->code,
                'members' => $seats->where('group_id', $group->id)->count(),
                'commissioners' => $this->commissioners($seats->where('group_id', $group->id)),
                'contests' => (int) ($contests[$group->id] ?? 0),
                'slates' => (int) ($slates[$group->id] ?? 0),
                'entries' => (int) ($entries[$group->id] ?? 0),
                'picks' => (int) ($picks[$group->id] ?? 0),
                'posts' => (int) ($posts[$group->id] ?? 0),
                'invites' => (int) ($invites[$group->id] ?? 0),
                'icon' => filled($group->icon),
            ])
            ->values()
            ->all();
    }

    /**
     * One note per member of the survivor, each carrying only what is true
     * for that reader. Rooted in group_members, never in entries: somebody
     * who has not picked yet is exactly who most needs to hear it.
     *
     * @param  array<string, mixed>  $plan
     * @return array{total: int, mail: int, inbox: int, push: int}
     */
    private function announce(Group $into, Contest $contest, array $plan, bool $freshStart): array
    {
        $readers = User::query()
            ->whereIn('id', GroupMember::query()->where('group_id', $into->id)->select('user_id'))
            ->withCount('pushSubscriptions')
            ->get()
            ->keyBy('id');

        $size = $contest->mode->engine($contest->settings)->slateSize();
        $deadline = Cadence::deadlineLabel();
        $sent = ['total' => 0, 'mail' => 0, 'inbox' => 0, 'push' => 0];

        foreach ($plan['recipients'] as $recipient) {
            $reader = $readers->get($recipient['user_id']);

            // Gone between the plan and now — skipped, never stood in for.
            if ($reader === null) {
                continue;
            }

            $reader->notify(new GroupsMerged(
                variant: $recipient['variant'],
                groupId: $into->id,
                group: $into->name,
                url: route('pickem.group', $into),
                mode: $contest->mode->value,
                size: $size,
                pivoted: $plan['pivot'] !== null,
                freshStart: $freshStart,
                former: $recipient['former'],
                ran: $recipient['ran'],
                runsIt: $recipient['runs_it'],
                count: count($plan['from']),
                deadline: $deadline,
            ));

            $sent['total']++;
            $sent['inbox']++;
            $sent['mail'] += $reader->email_verified_at !== null ? 1 : 0;
            $sent['push'] += $reader->push_subscriptions_count > 0 ? 1 : 0;
        }

        return $sent;
    }

    /**
     * The folded groups' icons, AFTER the commit: a file deleted inside a
     * transaction that then rolled back is an icon lost for a group that
     * still exists. A failed delete is a stray file, never a failed merge.
     *
     * @param  list<string>  $paths
     */
    private function forgetIcons(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk(config('cfb.upload_disk'))->delete($path);
            } catch (Throwable $e) {
                Log::warning("Merged group icon {$path} was not deleted: {$e->getMessage()}");
            }
        }
    }

    /**
     * @param  Collection<int, GroupMember>  $seats
     * @return list<string>
     */
    private function commissioners(Collection $seats): array
    {
        return $seats
            ->where('role', GroupMember::COMMISSIONER)
            ->map(fn (GroupMember $seat) => $this->label($seat->user))
            ->values()
            ->all();
    }

    /** Name and handle, for an operator reading a console. */
    private function label(?User $user): string
    {
        if ($user === null) {
            return '—';
        }

        $name = trim($user->first_name.' '.$user->last_name);

        return $user->handle === null ? $name : "{$name} (@{$user->handle})";
    }
}
