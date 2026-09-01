{{--
    THE PICK SURFACE — one published slate rendered as tappable matchup
    cards, embedded by every screen that lets (or shows) picking: the group
    clubhouse and the public contest room include this same file and mix in
    App\Livewire\Concerns\MakesPicks, so what a tap does can never drift
    between hosts.

    Expects from the including view:
      $slate        a PUBLISHED slate with games.game.homeTeam/awayTeam
                    (palette columns included), tiebreaker relations and
                    entries loaded
      $interactive  false renders the identical surface as a read-only
                    preview — the lobby outsider's view, and the
                    commissioner's preview-as-participant step

    The sub-chrome (status · progress · countdown) sticks under the app
    header and measures its own height into `--pickem-chrome` on the
    document element — the scoreboard's pattern, for the same reason: tier
    headings park against a measured edge, not a guessed constant, and
    Livewire's morph would strip an inline style from any node it renders.
--}}
@php
    $engine = $slate->contest->mode->engine($slate->contest->settings);

    $gameIds = $slate->games->pluck('id');
    $made = $gameIds->intersect($this->myPicks->keys())->count();

    $anyKicked = $slate->games->contains(fn ($slateGame) => $slateGame->game->hasKickedOff());

    $surfaceStatus = match (true) {
        $slate->status === App\Models\Slate::SETTLED => 'final',
        $slate->status === App\Models\Slate::PRELIM => 'prelim',
        $anyKicked => 'live',
        default => 'upcoming',
    };

    $firstKick = $slate->games->map(fn ($slateGame) => $slateGame->game->kickoff_at)->filter()->min();
    $secondsToKick = $surfaceStatus === 'upcoming' && $firstKick !== null
        ? max(0, (int) now()->diffInSeconds($firstKick, false))
        : 0;

    $tierGroups = $slate->games->groupBy(fn ($slateGame) => $slateGame->tier ?? 0)->sortKeys();
    $entry = $this->myEntries->get($slate->id);

    /*
     * THE ENTRY CHECKLIST, in three states. Derived from the picks
     * themselves rather than a stored flag, so a reload agrees and
     * changing a pick after the fact cannot un-say it. `picksAllIn`
     * without `entryIn` can only mean the week's question is unanswered,
     * which is why the middle state needs no extra read.
     */
    $picksAllIn = $gameIds->isNotEmpty() && $made >= $gameIds->count();
    $entryIn = $interactive && $picksAllIn && $this->entryIn($slate->id);

    /*
     * THE TALLBOY, and which card may take it. Eligibility is asked of the
     * ENGINE — built from this contest's own frozen settings — never of a
     * per-flavor list, because a dynamic room's slate is as big as the
     * Saturday allowed and a thin one puts ±5 over the leverage ceiling.
     *
     * ONE WAGER PER SLATE, which is what that ceiling is a guarantee about.
     * So every card offers it while nothing is staked, and once one is, the
     * offer collapses to the card holding it — nine disabled controls is a
     * screen arguing with itself. Read off `myPicks`, already loaded.
     */
    $takesTallboy = $engine->supportsTallboy();
    $crushedId = $takesTallboy
        ? $gameIds->first(fn (int $id) => (bool) $this->myPicks->get($id)?->locked)
        : null;
@endphp

<div
    x-data="{
        sync() {
            const chrome = this.$refs.chrome

            if (! chrome) return

            const offset = parseFloat(getComputedStyle(chrome).top) || 0

            document.documentElement.style.setProperty(
                '--pickem-chrome', (chrome.offsetHeight + offset) + 'px'
            )
        },
    }"
    x-init="
        sync()
        new ResizeObserver(() => sync()).observe($refs.chrome)
    "
    x-on:resize.window="sync()"
    class="flex flex-col gap-3"
>
    <div
        x-ref="chrome"
        class="sticky top-[var(--chrome-offset)] z-30 -mx-4 flex items-center justify-between gap-3 border-b border-zinc-100 bg-white px-4 py-2 dark:border-zinc-800/60 dark:bg-zinc-950"
    >
        <span class="shrink-0">
            <x-slate-status :status="$surfaceStatus" upcoming="Slate's up" class="text-sm" />
        </span>

        @if ($interactive && $this->needsHandle)
            {{-- The REASON the cards render locked, in the band that stays
                 on screen while they scroll — the claim box below is the
                 action; this is the explanation that travels with them. --}}
            <span class="min-w-0 truncate text-sm font-medium text-amber-600 dark:text-amber-500">
                {{ App\Support\Voice::line('picks.claim.reason') }}
            </span>
        @elseif ($interactive && in_array($surfaceStatus, ['upcoming', 'live'], true))
            {{-- Done, one step short, or still counting — the middle slot
                 says which, in the band that stays on screen while the
                 cards scroll. --}}
            @if ($entryIn)
                <span class="flex min-w-0 items-center gap-1.5 text-sm font-medium text-emerald-700 dark:text-emerald-400">
                    <flux:icon.check-circle-fill variant="micro" class="size-4 shrink-0" />
                    Entry in
                </span>
            @elseif ($picksAllIn)
                {{-- Every game picked and the week's question still open.
                     Amber, because it is the one thing left and nothing
                     else on the screen is asking for it — and a BUTTON:
                     the one thing left walks you to the box that takes it.
                     Smooth only where motion is welcome. --}}
                <button
                    type="button"
                    x-on:click="document.getElementById('tiebreaker-{{ $slate->id }}')?.scrollIntoView({
                        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                        block: 'center',
                    })"
                    class="focus-ring min-w-0 truncate text-sm font-medium text-amber-600 dark:text-amber-500"
                >
                    Tiebreaker left
                </button>
            @else
                <x-slate-progress :made="$made" :total="$gameIds->count()" class="min-w-0" />
            @endif
        @endif

        {{--
            Time until the first kickoff. Client-driven for the same reason
            the game page's refresh ring is: the screen only re-renders on
            interaction, and a countdown that never moved would not be one.
            The ring appears for the final hour and empties — time you have
            LEFT, running out — while days read as words.
        --}}
        @if ($secondsToKick > 0)
            <div
                wire:key="kickoff-countdown-{{ $slate->id }}"
                x-data="{
                    remaining: @js($secondsToKick),
                    timer: null,
                    start() {
                        if (this.remaining <= 0) return;
                        this.timer = setInterval(() => {
                            this.remaining = Math.max(0, this.remaining - 1);
                            if (this.remaining === 0) {
                                this.stop();
                                // The ring knows the exact second the rows
                                // lock; ONE refresh renders them locked, so
                                // the racing tap mostly cannot happen —
                                // MakesPicks' locked notice catches the rest.
                                $wire.$refresh();
                            }
                        }, 1000);
                    },
                    stop() {
                        if (this.timer) clearInterval(this.timer);
                        this.timer = null;
                    },
                    label() {
                        const s = this.remaining;
                        if (s <= 0) return 'Kickoff';
                        if (s >= 86400) return Math.floor(s / 86400) + 'd ' + Math.floor((s % 86400) / 3600) + 'h';
                        if (s >= 3600) return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
                        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
                    },
                }"
                x-init="start()"
                x-on:beforeunload.window="stop()"
                class="flex shrink-0 items-center gap-1.5 text-micro font-medium text-zinc-500"
            >
                {{-- The final hour, emptying. --}}
                <x-countdown-ring show="remaining > 0 && remaining < 3600" fraction="remaining / 3600" />

                <span class="tabular" x-text="label()"></span>
                <span>to kickoff</span>
            </div>
        @endif
    </div>

    {{-- YOUR ENTRY IS IN, said once and only by the act that finished it.
         The flag behind entryCelebrating() is a protected property, so it
         survives exactly this response — no toast, no confetti, no stored
         state to clear, and nothing to fire again when a pick changes
         later. Guarded on $interactive, which is what keeps it out of the
         builder's preview and an outsider's read-only view. --}}
    @if ($interactive && $this->entryCelebrating($slate->id))
        <div
            wire:key="entry-in-{{ $slate->id }}"
            x-data="{ shown: true }"
            x-show="shown"
            role="status"
            aria-live="polite"
            data-entry-celebration
            class="flex items-center gap-2.5 rounded-xl bg-emerald-50 py-2 pr-1 pl-3 ring-1 ring-emerald-200 motion-safe:animate-entry-in dark:bg-emerald-950/30 dark:ring-emerald-900"
        >
            <flux:icon.check-badge class="size-4 shrink-0 text-emerald-600 dark:text-emerald-500" />

            <p class="min-w-0 flex-1 text-sm text-zinc-700 dark:text-zinc-300">
                {{ App\Support\Voice::line('picks.entry.celebration') }}
                {{-- The highest-intent moment the surface produces walks
                     straight into the thread. Plain words, own door. --}}
                <a
                    href="{{ route('pickem.talk', $slate->contest->group) }}"
                    wire:navigate
                    class="font-semibold text-emerald-700 hover:underline dark:text-emerald-400"
                >Talk it over</a>
            </p>

            <flux:button
                x-on:click="shown = false"
                size="xs"
                square
                variant="ghost"
                icon="x-mark"
                class="shrink-0"
                aria-label="Dismiss"
            />
        </div>
    @endif

    {{-- The answer to the reader's last tap, in the surface where they
         tapped — never parked at the top of the page. Tone rides with the
         line, so a refusal can no longer wear a success box. --}}
    @if ($interactive && $this->notice)
        <x-notice :tone="$this->noticeTone">{{ $this->notice }}</x-notice>
    @endif

    {{-- A kicker room says its house rule out loud, over the slate. --}}
    @if (($kickerPoints = $engine->kickerPoints()) !== null && ($kickerNote = App\Support\Voice::line('picks.kicker.underdog_note', ['points' => $kickerPoints])) !== '')
        <div class="rounded-xl border border-zinc-200 px-4 py-3 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
            {{ $kickerNote }}
        </div>
    @endif

    {{--
        The Bear's table talk — Woodshed slates only. His theme is the
        instruction (who he rides), said plainly; the tagline under it is
        his voice, and his actual side sits pawed on every card below.
    --}}
    @if ($slate->bear_theme !== null)
        <div class="flex items-start gap-3 rounded-xl border border-red-900/40 bg-zinc-900 px-4 py-3 text-zinc-100 dark:border-red-950 dark:bg-black">
            <flux:icon.paw-print class="mt-0.5 size-5 shrink-0 text-red-500 dark:text-red-400" />
            <div class="min-w-0">
                <p class="text-sm font-semibold">{{ App\Services\Contests\BearPicks::themeLine($slate->bear_theme) }}</p>
                @if (($tagline = App\Support\Voice::line('picks.bear.tagline.'.$slate->bear_theme)) !== '')
                    <p class="pt-0.5 text-sm text-zinc-400">&ldquo;{{ $tagline }}&rdquo;</p>
                @endif
            </div>
        </div>
    @endif

    {{-- The claim moment: one field, then the surface unlocks. --}}
    @if ($interactive && $this->needsHandle)
        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ App\Support\Voice::line('picks.claim.heading') }}</flux:heading>
            <flux:subheading>{{ App\Support\Voice::line('picks.claim.body') }}</flux:subheading>
            <form wire:submit="claim" class="flex flex-col gap-3">
                {{-- The format rule stays plain — a joke would eat it. --}}
                <flux:input
                    wire:model="handle"
                    label="Handle"
                    description="Lowercase letters, numbers and underscores."
                    maxlength="20"
                    autocomplete="off"
                    x-mask:dynamic="$input.toLowerCase().replace(/[^a-z0-9_]/g, '').slice(0, 20)"
                />
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="claim" class="self-start">Claim it</flux:button>
            </form>
        </div>
    @endif

    @foreach ($tierGroups as $tier => $tierGames)
        <div wire:key="slate-{{ $slate->id }}-tier-{{ $tier }}" class="flex flex-col gap-2">
            @if ($tier !== 0)
                @php $tierPoints = $engine->pointsFor($tierGames->first()); @endphp

                <flux:subheading
                    class="sticky z-20 -mx-4 flex items-center gap-1.5 bg-white px-4 py-1.5 dark:bg-zinc-950"
                    style="top: var(--pickem-chrome, 0px)"
                >
                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">Tier {{ $tier }}</span>
                    <span class="text-micro text-zinc-400">{{ $tierPoints }} {{ Str::plural('point', $tierPoints) }} a game</span>
                </flux:subheading>
            @endif

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                @foreach ($tierGames as $slateGame)
                    <x-pick-card
                        wire:key="slate-{{ $slate->id }}-pick-{{ $slateGame->id }}"
                        :slate-game="$slateGame"
                        :pick="$this->myPicks->get($slateGame->id)"
                        :locked="$slateGame->game->hasKickedOff() || ($interactive && $this->needsHandle)"
                        :interactive="$interactive"
                        :tiebreaker="$slate->tiebreaker_slate_game_id === $slateGame->id"
                        :points="$engine->pointsFor($slateGame)"
                        :bear-team-id="$slateGame->bear_team_id"
                        :featured="$engine->supportsLock() && $slate->tiebreaker_slate_game_id === $slateGame->id"
                        :lockable="$interactive && $engine->supportsLock() && $slate->tiebreaker_slate_game_id === $slateGame->id"
                        :crushable="$takesTallboy && ($crushedId === null || $crushedId === $slateGame->id)"
                    />
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- The week's QUESTION, answered once per slate. --}}
    @if ($slate->tiebreakerGame)
        @php $tiebreakerLocked = $slate->tiebreakerGame->game->hasKickedOff() || $this->needsHandle; @endphp

        <div id="tiebreaker-{{ $slate->id }}" class="flex flex-col gap-2 rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ App\Support\Voice::line('picks.tiebreaker.hint') }}</p>

            @if ($interactive)
                <form wire:submit="saveTotal({{ $slate->id }})" class="flex items-end gap-2">
                    {{-- The criterion is an instruction and stays plain. --}}
                    <flux:input
                        wire:model="totals.{{ $slate->id }}"
                        type="number"
                        min="0"
                        max="{{ $slate->tiebreaker_metric?->maxPrediction() ?? 200 }}"
                        label="{{ $slate->tiebreaker_metric?->question($slate->tiebreakerGame, $slate->tiebreakerTeam) }}"
                        placeholder="{{ $entry?->tiebreaker_total }}"
                        class="max-w-48"
                        :disabled="$tiebreakerLocked"
                    />
                    <flux:button type="submit" size="sm" wire:loading.attr="disabled" wire:target="saveTotal" :disabled="$tiebreakerLocked">Save</flux:button>
                </form>

                {{-- Server-side refusal: the input's min/max decorate the
                     picker but do not block a wire:submit. --}}
                <flux:error name="totals.{{ $slate->id }}" />

                @if ($entry?->tiebreaker_total !== null)
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        Your call: <span class="tabular font-semibold">{{ $entry->tiebreaker_total }}</span>
                        @if ($slate->status === App\Models\Slate::SETTLED && $slate->tiebreaker_actual !== null)
                            · Actual: <span class="tabular font-semibold">{{ $slate->tiebreaker_actual }}</span>
                        @endif
                    </p>
                @endif
            @else
                <p class="text-sm font-medium">
                    {{ $slate->tiebreaker_metric?->question($slate->tiebreakerGame, $slate->tiebreakerTeam) }}
                </p>
            @endif
        </div>
    @endif
</div>
