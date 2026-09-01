<?php

namespace App\Livewire\Concerns;

use App\Actions\CrushTallboy;
use App\Actions\EnterTiebreaker;
use App\Actions\LockPick;
use App\Actions\MakePick;
use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PickLocked;
use App\Exceptions\WalletTooLight;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Support\PickemPulse;
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
     * The slate whose entry was COMPLETED by the mutation this response is
     * answering, or null.
     *
     * PROTECTED on purpose: Livewire serializes public properties only, so
     * this exists for exactly the one response that completed the entry and
     * is gone by the next. That is the whole persistence model — there is
     * no column, no session flag and nothing to clear, and a reload agrees
     * because completeness itself is derived (see entryComplete()).
     */
    protected ?int $entryJustCompleted = null;

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
        $slate = $this->slateOfGame($slateGameId);
        $wasComplete = $slate !== null && $this->entryComplete($slate);

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
        $this->noteCompletion($slate, $wasComplete);
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

    /**
     * The Tallboy toggle, the Lock's sibling.
     *
     * One extra refusal over lockPick(): the wallet can be short, and that
     * is the one a reader has to be TOLD about — an unaffordable tap that
     * re-rendered silently reads as a dead button. PickLocked covers both
     * kickoff races here: this game starting, and the game already carrying
     * the wager starting while somebody tries to move it off.
     */
    public function crushTallboy(int $slateGameId, bool $staked, CrushTallboy $action): void
    {
        try {
            $slateGame = SlateGame::query()->findOrFail($slateGameId);
            $action->handle(auth()->user(), $slateGame, $staked);
        } catch (WalletTooLight) {
            $this->say('picks.tallboy.too_light', tone: 'error');
        } catch (PickLocked) {
            $this->say('picks.locked.notice', tone: 'error');
        } catch (PickemParticipationGated) {
            $this->say('groups.verify_first', tone: 'error');
        } catch (HandleRequired) {
            $this->say('picks.claim.body', tone: 'error');
        } catch (NotGroupMember) {
            $this->say('talk.not_member', tone: 'error');
        } catch (ModelNotFoundException|InvalidArgumentException) {
            // A stale card, or a wager aimed at a contest that does not
            // take one — the refresh renders what is actually stakeable.
        }

        $this->refreshPicks();
    }

    public function saveTotal(int $slateId, EnterTiebreaker $action): void
    {
        $total = (int) ($this->totals[$slateId] ?? 0);

        $pickable = $this->slateById($slateId);
        $wasComplete = $pickable !== null && $this->entryComplete($pickable);

        // Held rather than said: when the answer COMPLETES the entry the
        // celebration below speaks for it, and two banners over one tap is
        // the app talking to itself.
        $saved = null;

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
            $saved = $entry->tiebreaker_total;
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
        $this->noteCompletion($pickable, $wasComplete);

        if ($saved !== null && $this->entryJustCompleted === null) {
            $this->say('picks.tiebreaker.saved', ['total' => $saved], tone: 'success');
        }
    }

    public function claim(): void
    {
        $this->say('picks.claim.done', ['handle' => $this->claimHandle()], tone: 'success');
    }

    /**
     * Is this reader's entry IN? Every game on the slate picked, and the
     * week's question answered when there is one.
     *
     * Derived, and stated exactly once — there is no `submitted_at` and no
     * submit button, because a stored flag can disagree with the picks it
     * claims to describe the moment one of them changes. Reads only what
     * the surface has already loaded. A WAGER IS NOT A STEP — neither the
     * Woodshed's Lock nor the Tallboy — so an entry with nothing staked is
     * a complete entry.
     */
    protected function entryComplete(Slate $slate): bool
    {
        $gameIds = $slate->games->pluck('id');

        if ($gameIds->isEmpty() || $gameIds->diff($this->myPicks->keys())->isNotEmpty()) {
            return false;
        }

        return $slate->tiebreaker_slate_game_id === null
            || $this->myEntries->get($slate->id)?->tiebreaker_total !== null;
    }

    /** Blade's door to it — a view cannot reach a protected method. */
    public function entryIn(int $slateId): bool
    {
        $slate = $this->slateById($slateId);

        return $slate !== null && $this->entryComplete($slate);
    }

    /**
     * Did THIS response complete the entry? True for exactly one render —
     * the property behind it is not serialized — so the celebration fires
     * on the completing act and never again. Changing a pick afterwards
     * cannot re-fire it, and nothing un-celebrates: the checklist stays
     * done because it is derived from the picks themselves.
     */
    public function entryCelebrating(int $slateId): bool
    {
        return $this->entryJustCompleted === $slateId;
    }

    /**
     * The false→true edge, and only that. A refused mutation changes
     * nothing, so it can never fire; an already-complete entry stays
     * quiet however many picks are changed after it.
     */
    private function noteCompletion(?Slate $slate, bool $wasComplete): void
    {
        if ($slate === null || $wasComplete) {
            return;
        }

        if ($this->entryComplete($slate)) {
            $this->entryJustCompleted = $slate->id;

            // The nav dot's cache may buy five minutes of anything except
            // a nag over this finished entry.
            PickemPulse::forgetAttention(auth()->user());
        }
    }

    /**
     * The pickable slate holding this game — resolved from what the
     * surface already loaded, never re-queried, and never lazily: a slate
     * fetched fresh by id carries no `games` relation and lazy loading is
     * off, so reading one would be a 500 on a tap.
     */
    private function slateOfGame(int $slateGameId): ?Slate
    {
        return $this->pickableSlates()->first(
            fn (Slate $slate) => $slate->games->contains(fn (SlateGame $game) => $game->id === $slateGameId)
        );
    }

    /** Same, by slate id. */
    private function slateById(int $slateId): ?Slate
    {
        return $this->pickableSlates()->first(fn (Slate $slate) => $slate->id === $slateId);
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
