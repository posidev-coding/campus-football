<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Jobs\SendWeeklyNewsletter;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * The weekly email, fanned out one job per reader.
 *
 * Modelled on cfb:coaches, and for the same reason: `allowFailures()` means one
 * bouncing address cannot cancel the batch, and a job per reader means one
 * malformed digest costs one email rather than the whole send.
 *
 * It makes no upstream requests at all — every number in it is already in our
 * database — so the feed_runs row it writes reports zero. That is honest rather
 * than cosmetic: the ledger records that the run happened and what it cost, and
 * for this one the cost is entirely our own queue.
 */
class SendNewsletterCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:newsletter
        {--user= : Send to one user id, ignoring their preference. For checking a template.}
        {--dry : Report who would receive it and send nothing}';

    protected $description = 'Send the weekly email to everyone who wants it';

    public function handle(): int
    {
        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            $this->info('Nobody to send to.');

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->info("Would send to {$recipients->count()} reader(s).");

            return self::SUCCESS;
        }

        $batchId = null;

        $this->trackRun('newsletter', null, function () use ($recipients, &$batchId): array {
            $batch = Bus::batch(
                $recipients->map(fn (int $id) => new SendWeeklyNewsletter($id))->all()
            )
                ->name('Weekly newsletter')
                // One bad address must not cancel the other 299.
                ->allowFailures()
                ->dispatch();

            $batchId = $batch->id;

            return ['records' => $recipients->count(), 'batch_id' => $batch->id];
        });

        $this->info("Queued {$recipients->count()} email(s). Batch {$batchId}.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, int>
     */
    private function recipients(): Collection
    {
        if ($id = $this->option('user')) {
            return collect([(int) $id]);
        }

        /*
         * Verified addresses only, and not merely to be tidy: an unverified
         * address is one nobody has proved they own, so mailing it weekly is
         * how a typo at signup becomes somebody else's spam complaint — and
         * complaints are what cost a sending domain its reputation.
         *
         * The job re-checks both of these when it runs. This query decides who
         * to queue; that check decides who to send to, and minutes can pass
         * between the two.
         */
        return User::query()
            ->where('newsletter_opt_in', true)
            ->whereNotNull('email_verified_at')
            ->pluck('id');
    }
}
