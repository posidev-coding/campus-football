<?php

use App\Enums\ContentRating;
use App\Models\FeedRun;
use App\Models\User;
use App\Models\WalletEntry;
use App\Notifications\VerificationReminderNotification;
use App\Support\Voice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/*
 * The self-destruct warning. The invariants: exactly one reminder per account,
 * only to accounts the purge is actually coming for, and the mail's own link
 * verifies — because a warning whose escape hatch is broken is just a
 * countdown.
 */

it('reminds an unverified account eleven days in, and stamps it', function () {
    Notification::fake();

    $due = User::factory()->unverified()->create(['created_at' => now()->subDays(12)]);

    $this->artisan('cfb:verification-reminders')->assertSuccessful();

    Notification::assertSentTo($due, VerificationReminderNotification::class);
    expect($due->fresh()->verification_reminded_at)->not->toBeNull();
});

it('skips the verified, the young, the already-warned, and admins', function () {
    Notification::fake();

    User::factory()->create(['created_at' => now()->subDays(20)]);
    User::factory()->unverified()->create(['created_at' => now()->subDays(5)]);
    User::factory()->unverified()->create([
        'created_at' => now()->subDays(20),
        'verification_reminded_at' => now()->subDay(),
    ]);
    User::factory()->admin()->unverified()->create(['created_at' => now()->subDays(20)]);

    $this->artisan('cfb:verification-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('warns exactly once across reruns', function () {
    Notification::fake();

    $due = User::factory()->unverified()->create(['created_at' => now()->subDays(12)]);

    $this->artisan('cfb:verification-reminders')->assertSuccessful();
    $this->artisan('cfb:verification-reminders')->assertSuccessful();

    Notification::assertSentToTimes($due, VerificationReminderNotification::class, 1);
});

it('reports without sending or stamping under --dry', function () {
    Notification::fake();

    $due = User::factory()->unverified()->create(['created_at' => now()->subDays(12)]);

    $this->artisan('cfb:verification-reminders', ['--dry' => true])->assertSuccessful();

    Notification::assertNothingSent();
    expect($due->fresh()->verification_reminded_at)->toBeNull();
});

it('queues the reminder mail', function () {
    // Queued like every branded mail here — a scheduled command must not sit
    // through SMTP round trips inline.
    expect(new VerificationReminderNotification)->toBeInstanceOf(ShouldQueue::class);
});

it('records its run in the feed ledger', function () {
    Notification::fake();

    User::factory()->unverified()->create(['created_at' => now()->subDays(12)]);

    $this->artisan('cfb:verification-reminders')->assertSuccessful();

    expect(FeedRun::where('command', 'verification-reminders')->where('status', FeedRun::COMPLETE)->exists())
        ->toBeTrue();
});

it("speaks the reader's own register from the queue", function () {
    // Deliberately NO actingAs: Voice::line falls back to auth()->user() when
    // `for:` is omitted, and a logged-in test recipient would let that bug
    // pass. Built the way the queue builds it — cold.
    $user = User::factory()->unverified()->create(['content_rating' => ContentRating::R]);

    $mail = (new VerificationReminderNotification)->toMail($user);

    expect(implode(' ', $mail->introLines))
        ->toContain('ghosted')
        ->toContain('3 days');
});

it('links a signed URL the stock verifier accepts — and the payout follows', function () {
    $user = User::factory()->unverified()->create(['created_at' => now()->subDays(12)]);

    $url = (new VerificationReminderNotification)->toMail($user)->actionUrl;

    $this->actingAs($user)->get($url)
        ->assertRedirect(route('home'))
        ->assertSessionHas('verify.moment');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(WalletEntry::where('user_id', $user->id)->count())->toBe(1);
});

it('speaks the reward and reminder mail keys in every register', function () {
    $pgReader = User::factory()->make(['content_rating' => ContentRating::Pg]);
    $rReader = User::factory()->make(['content_rating' => ContentRating::R]);

    foreach (['mail.verify.reward', 'mail.reminder.subject', 'mail.reminder.intro', 'mail.reminder.outro'] as $key) {
        $pg = Voice::line($key, ['days' => 3], $pgReader);
        $r = Voice::line($key, ['days' => 3], $rReader);

        expect($pg)->not->toBe('')
            ->and($r)->not->toBe('')
            ->and($pg)->not->toBe($r, "{$key} should escalate between registers");
    }
});
