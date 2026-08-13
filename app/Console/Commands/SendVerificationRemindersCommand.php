<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Models\User;
use App\Notifications\VerificationReminderNotification;
use Illuminate\Console\Command;

/**
 * Warn never-verified accounts that the clock is running.
 *
 * Half of the self-destruct contract: this stamps `verification_reminded_at`,
 * and `User::prunable()` refuses to delete anyone without a stamp at least
 * VERIFICATION_REMINDER_LEAD_DAYS old. Sending is therefore what ARMS the
 * purge — if this command never runs, nobody is ever pruned, which is the
 * correct failure direction for a query that deletes users.
 *
 * The stamp also makes the send idempotent: a rerun, a missed day, or a
 * backlog of old accounts on deploy day each get exactly one warning.
 */
class SendVerificationRemindersCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:verification-reminders
                            {--dry : Report who would be reminded and send nothing}';

    protected $description = 'Warn never-verified accounts three days before they self-destruct';

    public function handle(): int
    {
        $due = User::query()
            ->whereNull('email_verified_at')
            ->whereNull('verification_reminded_at')
            ->where('admin', false)
            ->where('created_at', '<=', now()->subDays(
                User::VERIFICATION_GRACE_DAYS - User::VERIFICATION_REMINDER_LEAD_DAYS
            ))
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nobody is due a reminder.');

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->table(['id', 'email', 'signed up'], $due->map(fn (User $user) => [
                $user->id, $user->email, $user->created_at->toDateString(),
            ]));

            return self::SUCCESS;
        }

        $this->trackRun('verification-reminders', null, function () use ($due) {
            foreach ($due as $user) {
                $user->notify(new VerificationReminderNotification);
                $user->forceFill(['verification_reminded_at' => now()])->save();
            }

            return $due->count();
        });

        $this->info("Reminded {$due->count()} unverified ".str('account')->plural($due->count()).'.');

        return self::SUCCESS;
    }
}
