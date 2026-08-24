<?php

namespace App\Actions;

use App\Jobs\AnnounceSlateResults;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\User;
use App\Services\Contests\ModeEngine;
use App\Services\Contests\PickGrader;
use App\Services\Contests\SpreadGrader;
use App\Services\Contests\WoodshedMode;
use Illuminate\Support\Collection;

/**
 * The week turns OFFICIAL: final regrade, the tiebreaker answered,
 * standings stamped, winners paid — once.
 *
 * Called only by the settle sweep, only past Cadence::officialFinal — the
 * stat-settling window is the caller's law; this action is the mechanics.
 * Everything before the claim is idempotent (regrading is pure, payouts
 * are KEYED so a double fire inserts zero rows), and the claim itself is
 * the atomic settled_at stamp: one row affected means this run settled,
 * zero means somebody already had.
 *
 * The tiebreak: top total wins; among tied totals the closest call to the
 * resolved actual takes it, a never-entered prediction loses to any
 * entered one, and an UNRESOLVABLE actual (the stat never synced) skips
 * the tiebreak entirely — tied winners share the week and are both paid,
 * the honest office-pool outcome, never an invented number.
 */
class SettleSlate
{
    public function __construct(
        private PickGrader $grader,
        private SpreadGrader $spreads,
        private GrantWalletEntry $wallet,
    ) {}

    public function handle(Slate $slate): bool
    {
        if (! in_array($slate->status, [Slate::PUBLISHED, Slate::PRELIM], true)) {
            return false;
        }

        $slate->loadMissing(['games.game', 'entries.user', 'contest', 'tiebreakerGame.game']);

        // A slate with an unfinished game cannot settle, whatever the clock
        // says — the rescue sweep will have regraded it by the next pass.
        if ($slate->games->contains(fn ($slateGame) => ! $slateGame->game->completed)) {
            return false;
        }

        // The OFFICIAL regrade: absorb any score correction since prelim.
        foreach ($slate->games as $slateGame) {
            $this->grader->gradeSlateGame($slateGame, $slateGame->game);
        }

        $actual = $slate->tiebreaker_metric?->resolveActual($slate);

        $totals = $slate->games
            ->flatMap(fn ($slateGame) => $slateGame->picks()->get())
            ->groupBy('user_id')
            ->map(fn ($picks) => (int) $picks->sum('points'));

        // The Bear's verdict lands BEFORE winners are drawn — the +5 is
        // part of your week. Strictly greater: tying the Bear pays nothing.
        // Your side of the comparison already carries the Lock math (it
        // rode in on pick points); his is raw tier value, he cannot lock.
        $engine = $slate->contest->mode->engine($slate->contest->settings);
        $bearTotal = $this->bearTotal($slate, $engine);

        if ($bearTotal !== null) {
            $totals = $totals->map(fn (int $points) => $points > $bearTotal
                ? $points + WoodshedMode::BEAR_BONUS
                : $points);
        }

        $winners = $this->winners($slate, $totals, $actual);

        foreach ($slate->entries as $entry) {
            $total = $totals[$entry->user_id] ?? 0;

            $entry->update([
                'final_points' => $total,
                'won' => in_array($entry->user_id, $winners, true),
                // Post-fold comparison equals the pre-fold verdict: the +5
                // keeps a beaten Bear beaten, and a tie was never folded.
                'beat_bear' => $bearTotal === null ? null : $total > $bearTotal,
            ]);
        }

        $this->pay($slate, $totals, $winners);

        /*
         * The claim, last: everything above survives a double fire.
         *
         * NOTHING BELOW MAY READ $slate->status OR $slate->settled_at. This
         * is a query-builder update, not a model save, so the in-memory
         * instance still says `published`/`prelim` with a null settled_at —
         * a guard written against it here would never fire, and would look
         * completely correct.
         */
        $claimed = Slate::query()
            ->whereKey($slate->id)
            ->whereNull('settled_at')
            ->update([
                'status' => Slate::SETTLED,
                'settled_at' => now(),
                'tiebreaker_actual' => $actual,
            ]);

        if ($claimed !== 1) {
            return false;
        }

        /*
         * The announcement hangs off the CLAIM, which is the only once-ever
         * signal in this path — and it is a dispatch rather than a send: the
         * job re-reads a committed row instead of trusting anything in memory
         * here, and takes its own separate claim so a queue retry cannot mail
         * the room twice. Settled_at claims the money; results_announced_at
         * claims the noise, and the two must be repairable apart.
         */
        AnnounceSlateResults::dispatch($slate->id);

        return true;
    }

    /**
     * The Bear's weekly raw total, or null when this slate fields no Bear.
     * Every game is complete by the settle guard above, and grading his
     * side is the same frozen-line arithmetic as anyone's — computed from
     * relations already in memory, zero extra queries.
     */
    private function bearTotal(Slate $slate, ModeEngine $engine): ?int
    {
        if (! $engine->hasBear() || $slate->bear_theme === null) {
            return null;
        }

        return (int) $slate->games
            ->filter(fn ($slateGame) => $slateGame->bear_team_id !== null)
            ->sum(fn ($slateGame) => $this->spreads->resultFor($slateGame, $slateGame->game, $slateGame->bear_team_id) === Pick::WIN
                ? $engine->pointsFor($slateGame)
                : 0);
    }

    /**
     * @param  Collection<int, int>  $totals
     * @return list<int> user ids sharing the week
     */
    private function winners(Slate $slate, $totals, ?int $actual): array
    {
        $entrants = $slate->entries->pluck('user_id');

        if ($entrants->isEmpty()) {
            return [];
        }

        $top = $entrants->map(fn (int $userId) => $totals[$userId] ?? 0)->max();
        $tied = $entrants->filter(fn (int $userId) => ($totals[$userId] ?? 0) === $top)->values();

        if ($tied->count() === 1 || $actual === null) {
            return $tied->all();
        }

        $predictions = $slate->entries->keyBy('user_id');

        $distance = fn (int $userId) => $predictions[$userId]->tiebreaker_total === null
            ? PHP_INT_MAX
            : abs($predictions[$userId]->tiebreaker_total - $actual);

        $best = $tied->map($distance)->min();

        return $tied->filter(fn (int $userId) => $distance($userId) === $best)->values()->all();
    }

    /**
     * @param  Collection<int, int>  $totals
     * @param  list<int>  $winners
     */
    private function pay(Slate $slate, $totals, array $winners): void
    {
        foreach ($slate->entries as $entry) {
            $points = $totals[$entry->user_id] ?? 0;

            if ($points > 0) {
                $this->wallet->handle(
                    $entry->user,
                    $points * GrantWalletEntry::PICKEM_POINTS_XP_EACH,
                    0,
                    GrantWalletEntry::REASON_PICKEM_POINTS,
                    "slate:{$slate->id}:pts",
                );
            }
        }

        foreach ($winners as $userId) {
            $winner = $slate->entries->firstWhere('user_id', $userId)?->user ?? User::find($userId);

            if ($winner !== null) {
                $this->wallet->handle(
                    $winner,
                    GrantWalletEntry::PICKEM_WIN_XP,
                    GrantWalletEntry::PICKEM_WIN_LATTES,
                    GrantWalletEntry::REASON_PICKEM_WIN,
                    "slate:{$slate->id}:win",
                );
            }
        }
    }
}
