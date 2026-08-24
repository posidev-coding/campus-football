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
            @if ($surfaceStatus === 'live')
                <span class="flex items-center gap-1 text-sm font-semibold text-red-600 dark:text-red-400">
                    <span class="size-1.5 animate-pulse rounded-full bg-current"></span>
                    Live
                </span>
            @elseif ($surfaceStatus === 'prelim')
                <flux:badge size="sm" color="amber">Preliminary</flux:badge>
            @elseif ($surfaceStatus === 'final')
                <flux:badge size="sm" color="green">Final</flux:badge>
            @else
                <flux:badge size="sm" color="green">Slate's up</flux:badge>
            @endif
        </span>

        @if ($interactive && $this->needsHandle)
            {{-- The REASON the cards render locked, in the band that stays
                 on screen while they scroll — the claim box below is the
                 action; this is the explanation that travels with them. --}}
            <span class="min-w-0 truncate text-sm font-medium text-amber-600 dark:text-amber-500">
                {{ App\Support\Voice::line('picks.claim.reason') }}
            </span>
        @elseif ($interactive && in_array($surfaceStatus, ['upcoming', 'live'], true))
            <x-slate-progress :made="$made" :total="$gameIds->count()" class="min-w-0" />
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
                <svg
                    x-show="remaining > 0 && remaining < 3600"
                    x-cloak
                    viewBox="0 0 24 24"
                    class="size-4 -rotate-90"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="12" r="9" fill="none" stroke-width="3"
                            class="stroke-zinc-200 dark:stroke-zinc-700" />
                    <circle cx="12" cy="12" r="9" fill="none" stroke-width="3" stroke-linecap="round"
                            class="stroke-blue-500 transition-[stroke-dashoffset] duration-1000 ease-linear motion-reduce:transition-none"
                            stroke-dasharray="56.55"
                            :style="`stroke-dashoffset: ${56.55 * (1 - remaining / 3600)}`"
                    />
                </svg>

                <span class="tabular" x-text="label()"></span>
                <span>to kickoff</span>
            </div>
        @endif
    </div>

    {{--
        The Bear's table talk — Woodshed slates only. His theme is the
        instruction (who he rides), said plainly; the tagline under it is
        his voice, and his actual side sits pawed on every card below.
    --}}
    {{-- A kicker room says its house rule out loud, over the slate. --}}
    @if (($kickerPoints = $engine->kickerPoints()) !== null && ($kickerNote = App\Support\Voice::line('picks.kicker.underdog_note', ['points' => $kickerPoints])) !== '')
        <div class="rounded-xl border border-zinc-200 px-4 py-3 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
            {{ $kickerNote }}
        </div>
    @endif

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
                <flux:button type="submit" variant="primary" class="self-start">Claim it</flux:button>
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

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
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
                    />
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- The week's QUESTION, answered once per slate. --}}
    @if ($slate->tiebreakerGame)
        @php $tiebreakerLocked = $slate->tiebreakerGame->game->hasKickedOff() || $this->needsHandle; @endphp

        <div class="flex flex-col gap-2 rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700">
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
                    <flux:button type="submit" size="sm" :disabled="$tiebreakerLocked">Save</flux:button>
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
