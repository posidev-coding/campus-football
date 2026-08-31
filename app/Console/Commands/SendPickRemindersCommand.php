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
        // A preview is not the scheduled run, so it stays off the ledger —
        // and it stamps nothing, so it must not answer for the wave either.
        if ($this->option('dry')) {
            $slates = PickReminders::dueSlates($wave);

            if ($slates->isEmpty()) {
                $this->info("Nothing due for {$wave}.");

                return 0;
            }

            $this->table(
                ["{$wave}: reader", 'cards', 'picks owed'],
                collect(PickReminders::owedBy($slates))->map(fn (array $cards, int $userId) => [
                    $userId, count($cards), collect($cards)->sum('owed'),
                ])->values(),
            );

            return 0;
        }

        $swept = 0;

        $sent = $this->trackRun('pick-reminders', null, function () use ($wave, &$swept): array {
            /*
             * A tick with no slate due is still a run, and it is most of them:
             * the sweep fires every fifteen minutes from 08:00 to 23:45 in
             * season and only the ticks inside a wave's window have anything
             * to answer for. The completed row with a zero count is what lets
             * the schedule panel tell "ran, nothing due" from "never ran" —
             * and this sweep only reached that panel at all once `pickem:`
             * became a reported prefix, so without this it would have arrived
             * there reading overdue on nearly every tick.
             */
            $slates = PickReminders::dueSlates($wave);

            if ($slates->isEmpty()) {
                return ['records' => 0, 'batch_id' => null];
            }

            $swept = $slates->count();
            $owed = PickReminders::owedBy($slates);
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
                    // Bulk mail drains on `default`, never on `live` where a
                    // Saturday's scores are waiting on a seconds-level pickup.
                    ->onQueue('default')
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

        if ($swept === 0) {
            $this->info("Nothing due for {$wave}.");

            return 0;
        }

        $this->info("Queued {$sent} ".str('reminder')->plural($sent)." for {$wave} across ".$swept.' '.str('slate')->plural($swept).'.');

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
