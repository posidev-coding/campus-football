<?php

namespace App\Console\Commands;

use App\Actions\MergeGroups;
use App\Enums\ContestMode;
use App\Exceptions\MergeBlocked;
use App\Models\Group;
use App\Models\GroupMember;
use App\Services\CfbCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fold private groups into one survivor — the operator's door onto
 * MergeGroups, and the only one.
 *
 * IRREVERSIBLE, so it is built to be read before it is run: with no
 * survivor named it lists every private group and its code; `--dry` prints
 * the whole plan (who moves, what is deleted, whose History and
 * weeks-played count shrink, who hears what) and touches nothing; and in
 * production a real run refuses without `--force`. There is no prompt to
 * answer, because the Cloud console cannot answer one.
 *
 * Both choices are REQUIRED on every run — the mode the survivor plays
 * and whether its season table starts over — because a default on a
 * command that deletes groups is a decision nobody made.
 *
 * Like `pickem:announce`, this is a repair lever rather than a scheduled
 * run, so it writes no feed_runs row. A real run prints, and logs, a
 * record of every id it changed — the flipped exhibition slates included,
 * which is what undoing a fresh start would need.
 */
class MergeGroupsCommand extends Command
{
    protected $signature = 'pickem:merge-groups
                            {into? : Invite code of the group that survives}
                            {--from=* : Invite codes of the groups folding into it (repeat, or comma-separate)}
                            {--mode= : What the survivor plays from here: classic, tiered, woodshed, or keep}
                            {--ledger= : fresh (the season table starts over for everyone) or keep}
                            {--dry : Print the plan and change nothing}
                            {--force : Required for a real run in production}';

    protected $description = 'Fold private groups into one survivor, delete the rest, and tell every member';

    public function handle(MergeGroups $merge): int
    {
        if (blank($this->argument('into'))) {
            $this->listPrivateGroups();
            $this->error('Name the group that survives: pickem:merge-groups <CODE> --from=<CODE>,<CODE> --mode=<mode> --ledger=<fresh|keep> --dry');

            return self::FAILURE;
        }

        $mode = $this->option('mode');
        $ledger = $this->option('ledger');

        if (! in_array($mode, [...array_column(ContestMode::cases(), 'value'), 'keep'], true)) {
            $this->error('Pass --mode=classic, tiered, woodshed, or keep. It is required: nobody should get a default here.');

            return self::FAILURE;
        }

        if (! in_array($ledger, ['fresh', 'keep'], true)) {
            $this->error('Pass --ledger=fresh (the season table starts over) or --ledger=keep. It is required.');

            return self::FAILURE;
        }

        $intoCode = Str::upper(trim((string) $this->argument('into')));
        $codes = $this->codes();

        $into = Group::query()->where('code', $intoCode)->first();
        $from = collect($codes)->map(fn (string $code) => Group::query()->where('code', $code)->first());

        $unknown = collect($codes)
            ->reject(fn (string $code, int $i) => $from[$i] !== null)
            ->when($into === null, fn (Collection $missing) => $missing->prepend($intoCode))
            ->unique()
            ->values();

        if ($unknown->isNotEmpty()) {
            $this->error('No group has the code '.$unknown->implode(', ').'. Run pickem:merge-groups with no arguments to list them.');

            return self::FAILURE;
        }

        $mode = $mode === 'keep' ? null : ContestMode::from($mode);
        $fresh = $ledger === 'fresh';

        $plan = $merge->plan($into, $from, $mode, $fresh);

        $this->report($plan);

        if ($plan['blockers'] !== []) {
            foreach ($plan['blockers'] as $blocker) {
                $this->error($blocker);
            }

            return self::FAILURE;
        }

        if ($this->option('dry')) {
            $this->info('Dry run: nothing was changed and nobody was told.');

            return self::SUCCESS;
        }

        if ($this->laravel->isProduction() && ! $this->option('force')) {
            $this->error('This deletes groups in production. Read the plan above, then run it again with --force.');

            return self::FAILURE;
        }

        try {
            $record = $merge->handle($into, $from, $mode, $fresh);
        } catch (MergeBlocked $blocked) {
            foreach ($blocked->reasons as $reason) {
                $this->error($reason);
            }

            return self::FAILURE;
        }

        $sent = $record['notified'];

        $this->info("Merged into {$into->name}. Queued {$sent['total']} notices (mail {$sent['mail']} · inbox {$sent['inbox']} · push {$sent['push']}).");
        $this->line(json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        Log::info('pickem:merge-groups', $record);

        return self::SUCCESS;
    }

    /**
     * The folding codes, uppercased, from repeats and comma lists alike.
     * Duplicates are KEPT so the plan can say so, rather than quietly
     * collapsing a typo into a smaller merge.
     *
     * @return list<string>
     */
    private function codes(): array
    {
        return collect($this->option('from'))
            ->flatMap(fn (string $value) => explode(',', $value))
            ->map(fn (string $code) => Str::upper(trim($code)))
            ->filter()
            ->values()
            ->all();
    }

    private function listPrivateGroups(): void
    {
        $year = app(CfbCalendar::class)->currentYear();

        $groups = Group::query()
            ->where('kind', Group::KIND_PRIVATE)
            ->withCount('memberships')
            ->with([
                'contests' => fn ($query) => $query->where('season_year', $year),
                'memberships' => fn ($query) => $query->where('role', GroupMember::COMMISSIONER)->with('user:id,first_name,last_name,handle'),
            ])
            ->orderBy('name')
            ->get();

        $this->table(
            ['Code', 'Name', 'Members', "Mode ({$year})", 'Mode change used', 'Commissioner'],
            $groups->map(fn (Group $group) => [
                $group->code,
                $group->name,
                $group->memberships_count,
                $group->contests->first()?->mode->label() ?? '—',
                $group->contests->first()?->mode_changed_at?->toDateString() ?? 'no',
                $group->memberships->map(fn (GroupMember $seat) => trim($seat->user?->first_name.' '.$seat->user?->last_name))->implode(', ') ?: '—',
            ])->all(),
        );
    }

    /**
     * The plan, as the operator needs to read it before anything happens.
     *
     * @param  array<string, mixed>  $plan
     */
    private function report(array $plan): void
    {
        $into = $plan['into'];

        $this->newLine();
        $this->line("<options=bold>Survivor:</> {$into['name']} ({$into['code']}) — {$into['members']} members, run by ".(implode(', ', $into['commissioners']) ?: 'nobody'));
        $this->line('  Plays '.($into['mode'] ?? 'no contest this season').($into['mode_changed_at'] !== null ? ", mode changed {$into['mode_changed_at']}" : ''));

        $this->newLine();
        $this->line('<options=bold>Folding in, then DELETED with everything below:</>');
        $this->table(
            ['Code', 'Name', 'Members', 'Commissioner', 'Contests', 'Slates', 'Entries', 'Picks', 'Talk posts', 'Invites', 'Icon'],
            collect($plan['from'])->map(fn (array $group) => [
                $group['code'],
                $group['name'],
                $group['members'],
                implode(', ', $group['commissioners']) ?: '—',
                $group['contests'],
                $group['slates'],
                $group['entries'],
                $group['picks'],
                $group['posts'],
                $group['invites'],
                $group['icon'] ? 'yes' : 'no',
            ])->all(),
        );

        $this->line(sprintf(
            'Seating %d mover(s): %d already in the survivor, %d unverified, %d collect the one-time first-group XP.',
            count($plan['movers']),
            $plan['overlap'],
            $plan['unverified'],
            $plan['first_group_xp'],
        ));
        $this->line(sprintf(
            'History: %d people lose the folded groups\' weeks, %d of them no longer in any of these groups (and not told).',
            $plan['history_lost'],
            $plan['former_members'],
        ));

        $pivot = $plan['pivot'];
        $this->line($pivot === null
            ? 'Mode: unchanged.'
            : "Mode: {$pivot['from']} → {$pivot['to']}, stamped for the season".($pivot['overrides'] ? ' (overriding an earlier change)' : '').", {$pivot['drafts']} draft(s) reset.");

        $this->line($plan['fresh_start'] === []
            ? 'Ledger: no settled weeks re-marked.'
            : 'Ledger: FRESH — settled slate(s) '.implode(', ', $plan['fresh_start']).' become exhibitions, so the season table starts at 0–0.');

        foreach ($plan['warnings'] as $warning) {
            $this->warn($warning);
        }

        $this->newLine();
        $this->line('<options=bold>Who hears what:</>');
        $this->table(
            ['Member', 'Note', 'From', 'Ran', 'Runs survivor', 'Email', 'Weeks played'],
            collect($plan['recipients'])->map(fn (array $recipient) => [
                $recipient['name'],
                $recipient['variant'],
                implode(', ', $recipient['former']) ?: '—',
                implode(', ', $recipient['ran']) ?: '—',
                $recipient['runs_it'] ? 'yes' : '',
                $recipient['verified'] ? 'yes' : 'no (inbox only)',
                $recipient['weeks_before'] === $recipient['weeks_after']
                    ? (string) $recipient['weeks_before']
                    : "{$recipient['weeks_before']} → {$recipient['weeks_after']}",
            ])->all(),
        );
    }
}
