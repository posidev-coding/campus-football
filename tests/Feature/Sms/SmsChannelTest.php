<?php

use App\Jobs\Middleware\ThrottleSms;
use App\Models\User;
use App\Notifications\VerifyPhoneNotification;
use App\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/** A notification that would text anybody the channel will let it. */
class TestSmsNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['vonage'];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)->content('Your team plays in an hour.');
    }
}

describe('who can be texted', function () {
    it('skips somebody who never opted in, and still would have been mailed', function () {
        /*
         * The consent gate lives on routeNotificationForVonage() rather than in
         * each notification's via(), so it cannot be forgotten by a new one.
         * Laravel skips a channel whose route is falsy, so this is not an error
         * to handle — the person simply is not on this channel.
         */
        NotificationFacade::fake();

        $user = User::factory()->create([
            'phone' => '+14155550123',
            'phone_verified_at' => now(),
            'sms_opt_in' => false,
        ]);

        expect($user->routeNotificationForVonage())->toBeNull()
            ->and($user->canReceiveSms())->toBeFalse();
    });

    it('texts somebody who opted in with a confirmed number', function () {
        $user = User::factory()->create([
            'phone' => '+14155550123',
            'phone_verified_at' => now(),
            'sms_opt_in' => true,
        ]);

        expect($user->routeNotificationForVonage())->toBe('+14155550123');
    });

    it('refuses an unconfirmed number even with consent on the record', function () {
        /*
         * Consent is the legal test; a VERIFIED number is what protects a
         * stranger. One mistyped digit is somebody else's phone, and unlike a
         * bounced email they experience it as spam from a company they have
         * never heard of.
         */
        $user = User::factory()->create([
            'phone' => '+14155550123',
            'phone_verified_at' => null,
            'sms_opt_in' => true,
        ]);

        expect($user->routeNotificationForVonage())->toBeNull();
    });

    it('defaults a brand new account to NO', function () {
        // Unlike the newsletter. Signing up cannot be read as handing over a
        // phone, and a default of true is not consent.
        expect(User::factory()->create()->sms_opt_in)->toBeFalse();
    });
});

describe('normalizing a number', function () {
    it('stores one shape, whatever was typed', function (string $input, ?string $expected) {
        // An inbound STOP arrives as a number and nothing else. If the carrier's
        // format and ours disagree by a character, the opt-out finds no user.
        expect(PhoneNumber::normalize($input))->toBe($expected);
    })->with([
        'ten digits' => ['4155550123', '+14155550123'],
        'formatted' => ['(415) 555-0123', '+14155550123'],
        'with country code' => ['14155550123', '+14155550123'],
        'already E.164' => ['+14155550123', '+14155550123'],
        'international' => ['+447700900123', '+447700900123'],
        'too short to guess' => ['5550123', null],
        'nonsense' => ['not a phone', null],
        'empty' => ['', null],
    ]);

    it('refuses to guess rather than inventing a country code', function () {
        // 12 digits with no plus could be anything; guessing turns it into a
        // stranger's phone in some other country.
        expect(PhoneNumber::normalize('123456789012'))->toBeNull();
    });
});

describe('opting in', function () {
    it('will not accept consent before the number is confirmed', function () {
        $user = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);

        Livewire::actingAs($user)
            ->test('account')
            ->set('sms_opt_in', true)
            ->assertHasErrors('sms_opt_in');

        expect($user->fresh()->sms_opt_in)->toBeFalse();
    });

    it('stamps when consent happened, and does not clear it on the way out', function () {
        $user = User::factory()->create([
            'phone' => '+14155550123',
            'phone_verified_at' => now(),
        ]);

        $component = Livewire::actingAs($user)->test('account')->set('sms_opt_in', true);

        $stamped = $user->fresh()->sms_opted_in_at;
        expect($stamped)->not->toBeNull();

        $component->set('sms_opt_in', false);

        /*
         * The stamp records that consent once HAPPENED, which stays true after
         * it is withdrawn — and it is what a carrier asks to see when vetting
         * the 10DLC campaign. Clearing it on opt-out would destroy the only
         * evidence that the original send was lawful.
         */
        expect($user->fresh()->sms_opt_in)->toBeFalse()
            ->and($user->fresh()->sms_opted_in_at?->timestamp)->toBe($stamped->timestamp);
    });

    it('does not store the number until a code proves it', function () {
        NotificationFacade::fake();

        $user = User::factory()->create(['phone' => null]);

        Livewire::actingAs($user)
            ->test('account')
            ->set('phone', '(415) 555-0123')
            ->call('sendPhoneCode')
            ->assertHasNoErrors();

        // Writing it before it is proved would let anybody park a stranger's
        // phone on their own account.
        expect($user->fresh()->phone)->toBeNull()
            ->and($user->fresh()->phone_verified_at)->toBeNull();

        NotificationFacade::assertSentOnDemand(VerifyPhoneNotification::class);
    });

    it('stores the number once the code is right, and not before', function () {
        NotificationFacade::fake();

        $user = User::factory()->create(['phone' => null]);

        $component = Livewire::actingAs($user)
            ->test('account')
            ->set('phone', '4155550123')
            ->call('sendPhoneCode');

        $code = Cache::get('phone-verify:'.$user->id)['code'];

        $component->set('phone_code', '000000')->call('confirmPhoneCode')->assertHasErrors('phone_code');
        expect($user->fresh()->phone)->toBeNull();

        $component->set('phone_code', $code)->call('confirmPhoneCode')->assertHasNoErrors();

        expect($user->fresh()->phone)->toBe('+14155550123')
            ->and($user->fresh()->phone_verified_at)->not->toBeNull();
    });

    it('rate-limits codes per USER, not per number', function () {
        /*
         * Keyed on the number, somebody could walk a range and use us as a free
         * SMS cannon at our own expense — this notification is transactional and
         * carries no daily budget, so this limit is the only thing between a
         * form and a bill.
         */
        NotificationFacade::fake();
        RateLimiter::clear('phone-code:1');

        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test('account')
            ->set('phone', '4155550123')
            ->call('sendPhoneCode')
            ->assertHasNoErrors();

        $component->set('phone', '4155550124')->call('sendPhoneCode')->assertHasErrors('phone');
    });

    it('clears consent when the number is removed', function () {
        // Consent to text a number we no longer hold means nothing, and leaving
        // it set would text the NEXT number added without asking again.
        $user = User::factory()->create([
            'phone' => '+14155550123',
            'phone_verified_at' => now(),
            'sms_opt_in' => true,
        ]);

        Livewire::actingAs($user)->test('account')->call('removePhone');

        expect($user->fresh()->phone)->toBeNull()
            ->and($user->fresh()->phone_verified_at)->toBeNull()
            ->and($user->fresh()->sms_opt_in)->toBeFalse();
    });
});

describe('the STOP webhook', function () {
    it('records an opt-out so we stop paying to talk to a wall', function () {
        /*
         * Carriers block the number at their end the moment somebody sends STOP,
         * so the next message "sends" successfully and goes nowhere — while
         * still costing the surcharge. Recording it is what stops us trying.
         */
        $user = User::factory()->create([
            'phone' => '+14155550123',
            'phone_verified_at' => now(),
            'sms_opt_in' => true,
        ]);

        $this->post(route('webhooks.sms.inbound'), [
            'msisdn' => '14155550123',
            'text' => 'STOP',
        ])->assertOk();

        expect($user->fresh()->sms_opt_in)->toBeFalse();
    });

    it('is idempotent, because a carrier may deliver one twice', function () {
        $user = User::factory()->create([
            'phone' => '+14155550123', 'phone_verified_at' => now(), 'sms_opt_in' => true,
        ]);

        $this->post(route('webhooks.sms.inbound'), ['msisdn' => '14155550123', 'text' => 'stop'])->assertOk();
        $this->post(route('webhooks.sms.inbound'), ['msisdn' => '14155550123', 'text' => 'stop'])->assertOk();

        expect($user->fresh()->sms_opt_in)->toBeFalse()
            // The record that consent once existed survives the opt-out.
            ->and($user->fresh()->sms_opted_in_at)->toBe($user->sms_opted_in_at);
    });

    it('never turns SMS back ON, which is what makes it safe to leave open', function () {
        /*
         * The endpoint is unauthenticated, so it is built so forging it is
         * pointless: STOP is honoured, START is not. The worst a forged request
         * achieves is stopping somebody's texts — the direction they could have
         * chosen anyway. Turning it back on requires signing in.
         */
        $user = User::factory()->create([
            'phone' => '+14155550123', 'phone_verified_at' => now(), 'sms_opt_in' => false,
        ]);

        foreach (['START', 'UNSTOP', 'YES', 'subscribe'] as $attempt) {
            $this->post(route('webhooks.sms.inbound'), ['msisdn' => '14155550123', 'text' => $attempt])->assertOk();
        }

        expect($user->fresh()->sms_opt_in)->toBeFalse();
    });

    it('matches on the WHOLE message, so "don\'t stop" is not an opt-out', function () {
        $user = User::factory()->create([
            'phone' => '+14155550123', 'phone_verified_at' => now(), 'sms_opt_in' => true,
        ]);

        $this->post(route('webhooks.sms.inbound'), ['msisdn' => '14155550123', 'text' => "don't stop"])->assertOk();

        expect($user->fresh()->sms_opt_in)->toBeTrue();
    });

    it('answers 200 for a number we do not hold, rather than making Vonage retry', function () {
        $this->post(route('webhooks.sms.inbound'), ['msisdn' => '19995550000', 'text' => 'STOP'])
            ->assertOk();
    });
});

describe('the daily budget', function () {
    it('releases rather than sends once it is spent', function () {
        config(['cfb.sms_daily_budget' => 1]);
        RateLimiter::clear(ThrottleSms::KEY);

        $job = new class
        {
            public int $released = 0;

            public function release($delay = 0): void
            {
                $this->released++;
            }
        };

        $sent = 0;
        $next = function () use (&$sent) {
            $sent++;
        };

        (new ThrottleSms)->handle($job, $next);
        (new ThrottleSms)->handle($job, $next);

        expect($sent)->toBe(1)->and($job->released)->toBe(1);
    });

    it('uses a POSITIVE window, or it would permit everything', function () {
        /*
         * `now()->addDay()->diffInSeconds()` is NEGATIVE 86400 under Carbon 3's
         * signed diffs, which expires the limiter key as it is written and makes
         * the throttle fail OPEN. ThrottleMail shipped with exactly that bug.
         * A hit followed by a reading of one attempt is the proof it decays
         * forward.
         */
        config(['cfb.sms_daily_budget' => 5]);
        RateLimiter::clear(ThrottleSms::KEY);

        (new ThrottleSms)->handle(new stdClass, fn () => null);

        expect(RateLimiter::attempts(ThrottleSms::KEY))->toBe(1);
    });
});

describe('the delivery-receipt webhook', function () {
    it('answers 200 for every status Vonage can send', function (string $status) {
        // Vonage retries a non-2xx, and no retry could fix a receipt — it would
        // only turn one confusing payload into a stream of them.
        $this->post(route('webhooks.sms.status'), [
            'msisdn' => '13365773502',
            'messageId' => 'abc-123',
            'status' => $status,
        ])->assertOk();
    })->with(['delivered', 'expired', 'failed', 'rejected', 'buffered', 'unknown', '']);

    it('logs a carrier REFUSAL loudly, because that one is ours to fix', function () {
        /*
         * `rejected` is what an unregistered 10DLC campaign looks like from
         * here: the send API already returned success and charged us, and this
         * is the only place the truth shows up.
         */
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'SMS refused by the carrier.'
                && $context['status'] === 'rejected'
                && $context['error_code'] === '29');

        $this->post(route('webhooks.sms.status'), [
            'msisdn' => '13365773502',
            'messageId' => 'abc-123',
            'status' => 'rejected',
            'err-code' => '29',
        ])->assertOk();
    });

    it('logs an ordinary non-delivery quietly', function () {
        // A handset that was off is the world being the world. Logging it at
        // the same level as a misconfiguration trains people to ignore both.
        Log::shouldReceive('warning')->once();
        Log::shouldReceive('error')->never();

        $this->post(route('webhooks.sms.status'), [
            'msisdn' => '13365773502', 'status' => 'expired',
        ])->assertOk();
    });

    it('masks the number, so a log is not a directory of phone numbers', function () {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $m, array $c) => $c['to'] === '••• 3502');

        $this->post(route('webhooks.sms.status'), [
            'msisdn' => '13365773502', 'status' => 'expired',
        ])->assertOk();
    });

    it('changes NO user state, however bad the receipt', function () {
        /*
         * A receipt describes one message. Most non-delivery is transient, so
         * unverifying a number on a single failure would quietly disable a
         * channel somebody consented to — the same shape as writing a default
         * when a feed returns nothing. Acting on receipts needs a message log
         * and a threshold, which is pick'em-era work.
         */
        $user = User::factory()->create([
            'phone' => '+13365773502', 'phone_verified_at' => now(), 'sms_opt_in' => true,
        ]);

        $this->post(route('webhooks.sms.status'), [
            'msisdn' => '13365773502', 'status' => 'rejected', 'err-code' => '29',
        ])->assertOk();

        expect($user->fresh()->sms_opt_in)->toBeTrue()
            ->and($user->fresh()->phone_verified_at)->not->toBeNull();
    });
});
