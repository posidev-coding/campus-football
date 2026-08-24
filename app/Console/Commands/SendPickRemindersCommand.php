<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Jobs\SendPickReminder;
use App\Models\Slate;
use App\Support\PickReminders;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * "Your picks are due" — two waves, one sweep.
 *
 * Wave one goes a day before the first kickoff, wave two ninety minutes
 * before it. Neither is anchored on the commissioner's deadline: that is
 * when an unpublished slate forfeits to the standard card, while players
 * lock GAME BY GAME at kickoff, so the first kickoff is the only clock a
 * player reminder can honestly be hung on.
 *
 * The per-slate stamp is what keeps a card from reminding four times an
 * hour — and a slate is stamped even when nobody was due, because "checked,
 * nothing to send" must not become "retry forever" at fifteen-minute
 * resolution. Same discipline as the kickoff sweep, same reason.
 *
 * One job per READER, not per slate: nothing caps how many contests a person
 * joins, and three separate emails on a Friday is a spam complaint against a
 * domain that also carries password resets.
 */
class SendPickRemindersCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'pickem:remind
                            {--wave= : remind|last_call, default both}
                            {--dry : Report what would be sent, send nothing, stamp nothing}';

    protected $description = 'Remind members whose picks are still open before their first kickoff';

    public function handle(): int
    {
        $waves = match ($this->option('wave')) {
            PickReminders::WAVE_REMIND => [PickReminders::WAVE_REMIND],
            PickReminders::WAVE_LAST_CALL => [PickReminders::WAVE_LAST_CALL],
            null => [PickReminders::WAVE_REMIND, PickReminders::WAVE_LAST_CALL],
            default => [],
        };

        if ($waves === []) {
            $this->error('Unknown wave. Use remind or last_call.');

            return self::FAILURE;
        }

        $reminded = 0;

        foreach ($waves as $wave) {
            $reminded += $this->sweep($wave);
        }

        return self::SUCCESS;
    }

    private function sweep(string $wave): int
    {
        $slates = PickReminders::dueSlates($wave);

        if ($slates->isEmpty()) {
            $this->info("Nothing due for {$wave}.");

            return 0;
        }

        $owed = PickReminders::owedBy($slates);

        if ($this->option('dry')) {
            $this->table(
                ["{$wave}: reader", 'cards', 'picks owed'],
                collect($owed)->map(fn (array $cards, int $userId) => [
                    $userId, count($cards), collect($cards)->sum('owed'),
                ])->values(),
            );

            return 0;
        }

        $sent = $this->trackRun('pick-reminders', null, function () use ($owed, $wave, $slates): array {
            $batchId = null;

            if ($owed !== []) {
                $batch = Bus::batch(
                    collect($owed)->map(fn (array $cards, int $userId) => new SendPickReminder(
                        $userId,
                        collect($cards)->pluck('slate_id')->all(),
                        $wave,
                    ))->values()->all(),
                )
                    ->name("Pick reminders ({$wave})")
                    // Bulk mail drains behind the backfill worker, never on
                    // `default` where FetchAthleteGameLog holds a spinner.
                    ->onQueue('backfill')
                    // One bad address must not cancel everybody else's nudge.
                    ->allowFailures()
                    ->dispatch();

                $batchId = $batch->id;
            }

            /*
             * STAMPED OUTSIDE THE RECIPIENT LOOP, and stamped even when
             * nobody was due. A slate where everyone has already picked is
             * still a slate this sweep has answered for; leaving it unstamped
             * would re-ask every fifteen minutes until kickoff.
             */
            $this->stamp($slates, $wave);

            return ['records' => count($owed), 'batch_id' => $batchId];
        });

        $this->info("Queued {$sent} ".str('reminder')->plural($sent)." for {$wave} across ".$slates->count().' '.str('slate')->plural($slates->count()).'.');

        return $sent;
    }

    /** @param  Collection<int, Slate>  $slates */
    private function stamp(Collection $slates, string $wave): void
    {
        Slate::query()
            ->whereIn('id', $slates->pluck('id'))
            ->update([
                $wave === PickReminders::WAVE_LAST_CALL ? 'last_call_sent_at' : 'picks_reminded_at' => now(),
            ]);
    }
}
