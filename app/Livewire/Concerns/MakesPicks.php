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
            // The row already renders locked; a race at kickoff just
            // re-renders into that state.
        } catch (PickemParticipationGated) {
            $this->notice = Voice::line('groups.verify_first');
        } catch (HandleRequired) {
            $this->notice = Voice::line('picks.claim.body');
        } catch (NotGroupMember) {
            // A commissioner removed them mid-session; the next tap must
            // say so, not 500.
            $this->notice = Voice::line('talk.not_member');
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
            // The wager froze with the game; the toggle renders spent.
        } catch (PickemParticipationGated) {
            $this->notice = Voice::line('groups.verify_first');
        } catch (HandleRequired) {
            $this->notice = Voice::line('picks.claim.body');
        } catch (NotGroupMember) {
            $this->notice = Voice::line('talk.not_member');
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
            $entry = $action->handle(auth()->user(), Slate::query()->findOrFail($slateId), $total);
            $this->notice = Voice::line('picks.tiebreaker.saved', ['total' => $entry->tiebreaker_total]);
        } catch (PickLocked) {
            // Locked with the game; the input renders disabled already.
        } catch (PickemParticipationGated) {
            $this->notice = Voice::line('groups.verify_first');
        } catch (HandleRequired) {
            $this->notice = Voice::line('picks.claim.body');
        } catch (NotGroupMember) {
            $this->notice = Voice::line('talk.not_member');
        } catch (ModelNotFoundException|InvalidArgumentException) {
            // An unpublished slate or an implausible answer — the refresh
            // shows the current card and the saved total, if any.
        }

        $this->refreshPicks();
    }

    public function claim(): void
    {
        $this->notice = Voice::line('picks.claim.done', ['handle' => $this->claimHandle()]);
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
