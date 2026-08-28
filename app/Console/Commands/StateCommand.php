<?php

namespace App\Console\Commands;

use App\Support\LiveState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * What is running right now, on one Saturday.
 *
 * The report an operator wants on a rehearsal morning and a session wants
 * before it touches a pick'em bug: which rooms are stocked, what state each
 * slate is in, whether the practice flag is set, how many people are seated
 * and how many of them have actually picked.
 *
 * Deliberately READ-ONLY and deliberately terminal-only for now. It stocks
 * nothing, publishes nothing and stamps nothing — and it adds no route, so
 * it widens no externally-reachable surface. `App\Support\LiveState` is
 * shaped so an `/ops/state` skin can be added later without a second
 * source of truth, the way TelemetrySnapshot already feeds `cfb:telemetry`
 * and `/ops/telemetry`.
 *
 * Always exits zero. `cfb:doctor` is the deploy gate and `pickem:preflight`
 * is the flip gate; this is a read, and a read that fails a pipeline
 * because a room is empty is a read somebody turns off.
 */
class StateCommand extends Command
{
    protected $signature = 'cfb:state
        {--saturday= : The Saturday to read, YYYY-MM-DD. Defaults to the one this week is on}
        {--json : Emit the raw state instead of a terminal read}';

    protected $description = 'What is running on one Saturday — rooms, slates, entries and the waves';

    public function handle(LiveState $state): int
    {
        $saturday = $this->saturday();

        if ($saturday === false) {
            $this->error('  --saturday must be a date, YYYY-MM-DD.');

            return self::INVALID;
        }

        $read = $state->build($saturday);

        if ($this->option('json')) {
            $this->output->writeln(json_encode($read, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->heading($read);
        $this->contests($read['contests']);
        $this->rest($read);

        return self::SUCCESS;
    }

    /** @return CarbonImmutable|null|false  False means the option was given and is not a date. */
    private function saturday(): CarbonImmutable|null|false
    {
        $given = $this->option('saturday');

        if ($given === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($given, config('cfb.timezone'))->startOfDay();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param  array<string, mixed>  $read */
    private function heading(array $read): void
    {
        $week = $read['season']['week'] === null ? 'no week' : "Week {$read['season']['week']}";
        $games = $read['games'];

        $this->newLine();
        $this->line("  <fg=gray>Saturday</> {$read['saturday']}  <fg=gray>·</>  {$week}  <fg=gray>·</>  {$read['season']['phase']}");
        $this->line(sprintf(
            '  <fg=gray>Games</>    %d in the window, %d lined, %d kicked, %d final',
            $games['in_window'], $games['lined'], $games['kicked'], $games['final'],
        ));
        $this->line('  <fg=gray>Clock</>    first kick '.$this->at($read['clock']['first_kickoff'])
            .'  <fg=gray>·</>  deadline '.$this->at($read['clock']['deadline'])
            .'  <fg=gray>·</>  official '.$this->at($read['clock']['official_final']));
    }

    /** @param  list<array<string, mixed>>  $contests */
    private function contests(array $contests): void
    {
        $this->newLine();
        $this->line('  <fg=gray>Contests · '.count($contests).' on this Saturday</>');

        if ($contests === []) {
            $this->line('  <fg=yellow>!</> nothing slated — no room is stocked for this Saturday.');

            return;
        }

        foreach ($contests as $row) {
            // Published is the only thing a slate MUST be by kickoff; the
            // rest of this line is reported, never judged.
            $mark = $row['status'] === 'published' ? '<fg=green>✓</>' : '<fg=yellow>!</>';
            $counts = $row['exhibition'] ? '<fg=cyan>practice</>' : 'counts';

            $this->newLine();
            $this->line(sprintf(
                '  %s <options=bold>%s</>  <fg=gray>#%d</>  %s  %s  %s',
                $mark,
                $row['group'] ?? 'unnamed group',
                $row['slate_id'],
                $row['mode_label'] ?? '—',
                $row['status'],
                $counts,
            ));

            $expected = $row['expected_games'];
            $sized = $expected === null || $row['games'] === $expected ? '' : '  <fg=yellow>(expected '.$expected.')</>';

            $this->line(sprintf(
                '      games %d%s   lined %d   tiered %d   tiebreaker %s',
                $row['games'], $sized, $row['lined'], $row['tiered'], $row['tiebreaker'] ? 'set' : '<fg=yellow>none</>',
            ));

            $this->line(sprintf(
                '      entries %d   picks %d/%d   empty %d   complete %d',
                $row['entries'], $row['picks_made'], $row['picks_possible'], $row['entries_empty'], $row['entries_complete'],
            ));

            $this->line('      reminded '.$this->at($row['picks_reminded_at'])
                .'  <fg=gray>·</>  last call '.$this->at($row['last_call_sent_at'])
                .'  <fg=gray>·</>  settled '.$this->at($row['settled_at'])
                .'  <fg=gray>·</>  announced '.$this->at($row['results_announced_at']));
        }
    }

    /** @param  array<string, mixed>  $read */
    private function rest(array $read): void
    {
        $groups = $read['groups']['by_kind'];
        $people = $read['people'];

        $this->newLine();
        $this->line('  <fg=gray>Groups</>');

        foreach ($groups as $kind => $counts) {
            $this->line(sprintf('    %-10s %d  <fg=gray>(%d filled)</>', $kind, $counts['total'], $counts['filled']));
        }

        if ($read['groups']['by_flavor'] !== []) {
            $flavors = collect($read['groups']['by_flavor'])
                ->map(fn (int $n, string $flavor): string => "{$flavor} {$n}")
                ->implode(', ');

            $this->line("    <fg=gray>flavors</>    {$flavors}");
        }

        $this->newLine();
        $this->line('  <fg=gray>People</>');
        $this->line(sprintf(
            '    %d accounts  <fg=gray>·</>  %d verified  <fg=gray>·</>  %d onboarded  <fg=gray>·</>  %d admin',
            $people['users'], $people['verified'], $people['onboarded'], $people['admins'],
        ));
        $this->line(sprintf(
            '    push: %d %s across %d %s',
            $people['push_people'], $people['push_people'] === 1 ? 'person' : 'people',
            $people['push_devices'], $people['push_devices'] === 1 ? 'device' : 'devices',
        ));

        if ($people['push_devices'] === 0) {
            $this->line('    <fg=yellow>!</> nobody has granted push — the push path will run against no audience.');
        }

        $this->newLine();
    }

    /** A stamp, in league time. Null renders as an em dash — never as "now" or a zero. */
    private function at(?string $iso): string
    {
        return $iso === null
            ? '<fg=gray>—</>'
            : CarbonImmutable::parse($iso)->timezone(config('cfb.timezone'))->format('D g:ia');
    }
}
