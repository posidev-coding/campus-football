<?php

namespace App\Livewire\Concerns;

use App\Actions\EnterTiebreaker;
use App\Actions\LockPick;
use App\Actions\MakePick;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PickLocked;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Support\Voice;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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
    /** @var array<int, int> tiebreaker input per slate id */
    public array $totals = [];

    public string $handle = '';

    public ?string $notice = null;

    /**
     * The published slates whose games this screen lets the viewer pick.
     *
     * @return Collection<int, Slate>
     */
    abstract protected function pickableSlates(): Collection;

    #[Computed]
    public function needsHandle(): bool
    {
        return auth()->user()?->handle === null;
    }

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

    public function pick(int $slateGameId, int $teamId, MakePick $action): void
    {
        $slateGame = SlateGame::query()->findOrFail($slateGameId);

        try {
            $action->handle(auth()->user(), $slateGame, $teamId);
        } catch (PickLocked) {
            // The row already renders locked; a race at kickoff just
            // re-renders into that state.
        } catch (PickemParticipationGated) {
            $this->notice = Voice::line('groups.verify_first');
        }

        $this->refreshPicks();
    }

    public function lockPick(int $slateGameId, bool $locked, LockPick $action): void
    {
        $slateGame = SlateGame::query()->findOrFail($slateGameId);

        try {
            $action->handle(auth()->user(), $slateGame, $locked);
        } catch (PickLocked) {
            // The wager froze with the game; the toggle renders spent.
        } catch (PickemParticipationGated) {
            $this->notice = Voice::line('groups.verify_first');
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
        }

        $this->refreshPicks();
    }

    public function claim(): void
    {
        $validated = $this->validate([
            'handle' => [
                'required', 'string', 'min:3', 'max:20',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('users')->ignore(auth()->id()),
            ],
        ], [
            'handle.regex' => 'Handles use lowercase letters, numbers and underscores.',
        ]);

        auth()->user()->update(['handle' => $validated['handle']]);

        $this->notice = Voice::line('picks.claim.done', ['handle' => $validated['handle']]);
        unset($this->needsHandle);
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
