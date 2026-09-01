<?php

namespace App\Listeners;

use App\Actions\GrantWalletEntry;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * Confirming your email pays out: the first Tallboy and the first real XP.
 *
 * Verification is the moment the wallet turns on — it is deliberately the
 * gate for all earning (bar the one seeded first-team grant) — so the payout
 * rides the Verified event itself rather than any screen. The grant's
 * idempotency key absorbs double fires: revisiting a stale verification link
 * re-dispatches the event and pays nothing the second time.
 *
 * Synchronous on purpose. One insert is cheaper than a queue round trip, and
 * the chips should already show the payout on the page the verify link lands
 * on.
 */
class GrantVerificationReward
{
    public function __construct(protected GrantWalletEntry $grant) {}

    public function handle(Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->grant->handle(
            $event->user,
            xp: GrantWalletEntry::VERIFICATION_XP,
            credits: GrantWalletEntry::VERIFICATION_CREDITS,
            reason: GrantWalletEntry::REASON_EMAIL_VERIFIED,
            key: GrantWalletEntry::REASON_EMAIL_VERIFIED,
        );
    }
}
