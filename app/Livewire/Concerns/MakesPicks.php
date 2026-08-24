<?php

namespace App\Livewire\Concerns;

use App\Actions\EnterTiebreaker;
use App\Actions\LockPick;
use App\Actions\MakePick;
use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PickLocked;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Support\Voice;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * The pick surface's server half, shared by every screen that embeds
 * `partials/pick-slate` — the group clubhouse and the public contest room
 * render the same partial and mix in this trait, so the two hosts cannot
 * drift apart in what a tap does.
 *
 * A host implements {@see pickableSlates()} to say WHICH published slates
 * its surface shows; everything else — the viewer's picks and entries, the
 * tap, the tiebreaker call, the handle claim — lives here once. Every
 * mutation rides an Action carrying its own gates (lock, membership,
 * verified email); the trait's catches are presentation.
 */
trait MakesPicks
{
    use ClaimsHandle;

    /** @var array<int, int> tiebreaker input per slate id */
    public array $totals = [];

    public ?string $notice = null;

    /** What kind of answer the notice is — x-notice's tone prop. */
    public string $noticeTone = 'neutral';

    /**
     * The published slates whose games this screen lets the viewer pick.
     *
     * @return Collection<int, Slate>
     */
    abstract protected function pickableSlates(): Collection;

    /** @return Collection<int, Pick> keyed by slate_game_id */
    #[Computed]
    public function myPicks(): Collection
    {
        $gameIds = $this->pickableSlates()->flatMap(fn (Slate $slate) => $slate->games->pluck('id'));

        if ($gameIds->isEmpty() || auth()->guest()) {
            return collect();
        }

        return Pick::query()
            ->where('user_id', auth()->id())
            ->whereIn('slate_game_id', $gameIds)
            ->get()
            ->keyBy('slate_game_id');
    }

    /** @return Collection<int, SlateEntry> keyed by slate_id */
    #[Computed]
    public function myEntries(): Collection
    {
        $slateIds = $this->pickableSlates()->pluck('id');

        if ($slateIds->isEmpty() || auth()->guest()) {
            return collect();
        }

        return SlateEntry::query()
            ->where('user_id', auth()->id())
            ->whereIn('slate_id', $slateIds)
            ->get()
            ->keyBy('slate_id');
    }

    /*
     * The catch lists below are the surface's whole contract with the
     * Actions: EVERYTHING MakePick and its siblings can throw is either a
     * notice or a deliberate silent re-render, because anything uncaught
     * is a raw 500 on a tap. Silent is chosen only where the refreshed
     * render already explains itself — a locked row renders locked, a
     * stale card (unpublished, rebuilt, re-slated) simply is not there
     * anymore, and an implausible payload re-renders the current truth.
     */

    public function pick(int $slateGameId, int $teamId, MakePick $action): void
    {
        try {
            $slateGame = SlateGame::query()->findOrFail($slateGameId);
            $action->handle(auth()->user(), $slateGame, $teamId);
        } catch (PickLocked) {
            // A race at kickoff: the row rendered OPEN when they tapped,
            // so silence reads as a dead button. Say it, then the refresh
            // below renders the row locked.
            $this->say('picks.locked.notice', tone: 'error');
        } catch (PickemParticipationGated) {
            $this->say('groups.verify_first', tone: 'error');
        } catch (HandleRequired) {
            $this->say('picks.claim.body', tone: 'error');
        } catch (NotGroupMember) {
            // A commissioner removed them mid-session; the next tap must
            // say so, not 500.
            $this->say('talk.not_member', tone: 'error');
        } catch (ModelNotFoundException|InvalidArgumentException) {
            // A stale card after an unpublish or rebuild — the refresh
            // renders the current truth, which no longer has this control.
        }

        $this->refreshPicks();
    }

    public function lockPick(int $slateGameId, bool $locked, LockPick $action): void
    {
        try {
            $slateGame = SlateGame::query()->findOrFail($slateGameId);
            $action->handle(auth()->user(), $slateGame, $locked);
        } catch (PickLocked) {
            // Same race as pick(): the toggle rendered live when tapped.
            $this->say('picks.locked.notice', tone: 'error');
        } catch (PickemParticipationGated) {
            $this->say('groups.verify_first', tone: 'error');
        } catch (HandleRequired) {
            $this->say('picks.claim.body', tone: 'error');
        } catch (NotGroupMember) {
            $this->say('talk.not_member', tone: 'error');
        } catch (ModelNotFoundException|InvalidArgumentException) {
            // Stale card, or a Lock aimed at a game that stopped being
            // featured — the refresh renders what is actually stakeable.
        }

        $this->refreshPicks();
    }

    public function saveTotal(int $slateId, EnterTiebreaker $action): void
    {
        $total = (int) ($this->totals[$slateId] ?? 0);

        try {
            $slate = Slate::query()->findOrFail($slateId);

            /*
             * Validated HERE, not only client-side: min/max on a number
             * input decorate the picker but do not block a wire:submit,
             * so 9999 sailed straight into the action. The action's own
             * plausibility throw stays underneath as the defense against
             * a caller that skips this.
             */
            $max = $slate->tiebreaker_metric?->maxPrediction() ?? 200;

            if ($total < 0 || $total > $max) {
                $this->addError("totals.{$slateId}", Voice::line('picks.tiebreaker.invalid', ['max' => $max]));

                return;
            }

            $this->resetErrorBag("totals.{$slateId}");

            $entry = $action->handle(auth()->user(), $slate, $total);
            $this->say('picks.tiebreaker.saved', ['total' => $entry->tiebreaker_total], tone: 'success');
        } catch (PickLocked) {
            // The same kickoff race as pick() — the input rendered enabled.
            $this->say('picks.locked.notice', tone: 'error');
        } catch (PickemParticipationGated) {
            $this->say('groups.verify_first', tone: 'error');
        } catch (HandleRequired) {
            $this->say('picks.claim.body', tone: 'error');
        } catch (NotGroupMember) {
            $this->say('talk.not_member', tone: 'error');
        } catch (ModelNotFoundException|InvalidArgumentException) {
            // An unpublished slate or an implausible answer — the refresh
            // shows the current card and the saved total, if any.
        }

        $this->refreshPicks();
    }

    public function claim(): void
    {
        $this->say('picks.claim.done', ['handle' => $this->claimHandle()], tone: 'success');
    }

    /** One door for the notice, so the tone can never drift from the line. */
    protected function say(string $key, array $replace = [], string $tone = 'neutral'): void
    {
        $this->notice = Voice::line($key, $replace);
        $this->noticeTone = $tone;
    }

    /**
     * A host with more state riding the pick (live standings, entry
     * counts) overrides this — aliased in via its `use` — and unsets its
     * own computeds after calling this one.
     */
    protected function refreshPicks(): void
    {
        unset($this->myPicks, $this->myEntries);
    }
}
