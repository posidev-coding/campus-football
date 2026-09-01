<?php

namespace App\Actions;

use App\Models\User;

/**
 * Arriving at Picks: where the Tallboy economy switches on and restocks.
 *
 * The economy starts when the reader MEETS it, not at signup. Credits are
 * earned everywhere and spent only in the Lobby, so a wallet stocked before
 * anybody has read the promise is paying for a screen they have not opened;
 * `users.picks_first_seen_at` is that start line, and it is also the
 * once-ever fact a first-visit tour hangs off.
 *
 * GRANTED LAZILY, NEVER SWEPT. A scheduled weekly job would write a row for
 * every activated account whether they showed up or not; this pays the
 * people who came back, and the week-stamped key is the cap — see
 * {@see GrantWalletEntry::topOff()}. Two calls in one week write one row,
 * and the second computes nothing.
 *
 * Fired from the screen's mount(), NEVER from render(): a Livewire re-render
 * is cheap and frequent, and while the key would stop it paying twice,
 * nothing would stop it asking twice. The Film Room learned this first.
 */
class EnterPicks
{
    public function __construct(private GrantWalletEntry $wallet) {}

    /**
     * @return array{first_visit: bool, rung_ups: array<string, int>, topped_off: int|null}
     */
    public function handle(User $user): array
    {
        $firstVisit = ! $user->hasSeenPicks();

        if ($firstVisit) {
            // forceFill + save rather than update(): the column is not
            // fillable, and this is the app stamping a fact about the
            // reader rather than the reader editing themselves.
            $user->forceFill(['picks_first_seen_at' => now()])->save();
        }

        return [
            'first_visit' => $firstVisit,
            // Order matters for the reader, not the ledger: rungs are back
            // pay for climbing and land first, so the top-off then measures
            // the balance they actually hold.
            'rung_ups' => $this->wallet->rungUps($user),
            'topped_off' => $this->wallet->topOff($user),
        ];
    }
}
