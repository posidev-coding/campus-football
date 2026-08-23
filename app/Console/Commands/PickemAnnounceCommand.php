<?php

namespace App\Console\Commands;

use App\Jobs\AnnounceSlateResults;
use App\Models\Slate;
use Illuminate\Console\Command;

/**
 * Re-announce a settled slate's results.
 *
 * The repair half of the two-claim design. `settled_at` claims the money and
 * `results_announced_at` claims the noise, so an announcement that went out
 * wrong — bad copy, a template that threw, a batch that died halfway — can
 * be replayed by clearing the second stamp while the first stands. Nothing
 * in this path can reach the wallet: payouts are keyed and settlement is
 * already spent.
 *
 * Never a "settle now" button in disguise. It refuses a slate that has not
 * settled, because announcing a week that is not official is the one thing
 * worse than announcing it twice.
 */
class PickemAnnounceCommand extends Command
{
    protected $signature = 'pickem:announce
                            {--slate= : The slate id to re-announce}
                            {--dry : Report what would be re-announced and do nothing}';

    protected $description = 'Replay the results announcement for a settled slate';

    public function handle(): int
    {
        $slate = Slate::query()->with('contest.group')->find($this->option('slate'));

        if ($slate === null) {
            $this->error('No such slate. Pass --slate=<id>.');

            return self::FAILURE;
        }

        if ($slate->settled_at === null) {
            $this->error("Slate {$slate->id} has not settled. This command announces, it never settles.");

            return self::FAILURE;
        }

        $this->line("{$slate->contest->group->name} — {$slate->saturday->toDateString()}, announced ".
            ($slate->results_announced_at?->toDateTimeString() ?? 'never'));

        if ($this->option('dry')) {
            return self::SUCCESS;
        }

        // Clearing the stamp is what re-arms the job's own claim.
        $slate->forceFill(['results_announced_at' => null])->save();

        AnnounceSlateResults::dispatch($slate->id);

        $this->info("Queued a fresh announcement for slate {$slate->id}.");

        return self::SUCCESS;
    }
}
