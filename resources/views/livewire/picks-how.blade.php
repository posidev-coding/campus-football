<?php

use App\Actions\GrantWalletEntry;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Enums\LobbyShelf;
use App\Support\Voice;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * HOW THIS WORKS — the Picks area's reference screen.
 *
 * The economy put a second thing to understand on top of three contest
 * modes that were only ever explained in the store. A reader standing on
 * their own week should not have to walk into the Lobby to find out what
 * the number in their header buys.
 *
 * A DESTINATION rather than a disclosure at the foot of My Picks: that
 * screen already carries the week, the seats, the results and the ladder,
 * and the Lobby folded its own rules away for exactly this reason. It is
 * reached from a link row on My Picks and lights that section in the nav
 * — a fifth chip in a four-chip strip is a nav decision this screen does
 * not need.
 *
 * NOTHING HERE IS RESTATED. The mode rules are ContestMode::ruleLines()
 * through the same x-mode-rules card the Lobby uses; the shared laws are
 * one partial both screens include; the room grid reads LobbyFlavor and
 * the engine, and the cooler's tiers come off GrantWalletEntry's
 * constants — so a rebalance moves this screen without anybody editing it.
 */
new class extends Component
{
    /**
     * THE COOLER, as the three tiers a reader plans a week with.
     *
     * Derived from the constants the grant itself reads, so the sentence on
     * screen cannot promise a number the ledger will not pay. "Holding" is
     * the reader's own balance when they have one, which is what turns a
     * rule into an answer: the row they are standing in is marked.
     *
     * @return list<array{range: string, credits: int, plain: string, mine: bool}>
     */
    #[Computed]
    public function cooler(): array
    {
        $balance = auth()->check() ? auth()->user()->walletTotals()['credits'] : null;

        $empty = GrantWalletEntry::COOLER_EMPTY_AT;
        $full = GrantWalletEntry::COOLER_CAPACITY;

        $tiers = [
            ['range' => $empty.' or fewer', 'credits' => GrantWalletEntry::TOPOFF_EMPTY_CREDITS, 'plain' => "Cooler's empty — restock."],
            ['range' => ($empty + 1).' to '.($full - 1), 'credits' => GrantWalletEntry::TOPOFF_ROOM_CREDITS, 'plain' => 'Room left — top it off.'],
            ['range' => $full.' or more', 'credits' => 0, 'plain' => "You're stocked."],
        ];

        return array_map(fn (array $tier, int $index): array => [
            ...$tier,
            // Matched by the AMOUNT the grant would actually pay, never by
            // re-deriving the bands here — two ladders is one ladder that
            // will disagree.
            'mine' => $balance !== null && GrantWalletEntry::topOffFor($balance) === $tier['credits'],
        ], $tiers, array_keys($tiers));
    }

    /** What the reader is holding right now, or null for a guest. */
    #[Computed]
    public function balance(): ?int
    {
        return auth()->check() ? auth()->user()->walletTotals()['credits'] : null;
    }

    /**
     * EVERY ROOM, and the two facts a Tallboy decides about it.
     *
     * Read off the enums that already sort the store — the price is the
     * shelf's, and the wager is the ENGINE's, asked of the flavor's own
     * settings. A dynamic room is answered honestly rather than flatly:
     * its slate is as big as the Saturday allows, so "yes" is conditional
     * on a card big enough to carry the swing, and saying otherwise is the
     * room lying about a week it has not dealt yet.
     *
     * The flavorless House room is appended by hand because it is the
     * absence of a flavor and no enum case can represent it.
     *
     * @return list<array{name: string, shelf: string, entry: int, wager: string, rule: string}>
     */
    #[Computed]
    public function rooms(): array
    {
        $rows = array_map(function (LobbyFlavor $flavor): array {
            $engine = $flavor->mode()->engine($flavor->settings());

            return [
                'name' => $flavor->label(),
                'shelf' => $flavor->shelf()->heading(),
                'entry' => $flavor->shelf()->entryCredits(),
                'wager' => match (true) {
                    ! $engine->supportsTallboy() => 'No',
                    $flavor->dynamicSize() => 'On a full card',
                    default => 'Yes',
                },
                'rule' => $flavor->dynamicSize() && $engine->supportsTallboy()
                    ? 'This room deals as many games as the Saturday allows, and takes the wager on any card big enough to carry it.'
                    : $engine->tallboyRule(),
            ];
        }, LobbyFlavor::cases());

        $house = ContestMode::Classic->engine();

        $rows[] = [
            'name' => 'House rooms',
            'shelf' => LobbyShelf::House->heading(),
            'entry' => LobbyShelf::House->entryCredits(),
            'wager' => 'Yes',
            'rule' => $house->tallboyRule(),
        ];

        // Shelf order is the store's own order — the LobbyShelf case order
        // IS the display order — so the grid and the Lobby read the same
        // way round without a second opinion about it.
        $order = [];

        foreach (LobbyShelf::cases() as $index => $shelf) {
            $order[$shelf->heading()] = $index;
        }

        usort($rows, fn (array $a, array $b) => $order[$a['shelf']] <=> $order[$b['shelf']]);

        return $rows;
    }
}; ?>

<div class="flex flex-col gap-5 md:mx-auto md:w-full md:max-w-3xl">
    <h1 class="sr-only">How Pick'em works</h1>

    {{-- The slim band and the way back, the Talk screen's grammar: this is
         a side room off My Picks, not a place in the section strip. --}}
    <div class="flex items-center gap-3">
        <a
            href="{{ route('pickem.home') }}"
            wire:navigate
            aria-label="Back to My Picks"
            class="focus-ring flex size-9 shrink-0 items-center justify-center rounded-lg border border-zinc-200 text-zinc-500 transition-colors hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-700 dark:hover:border-zinc-600 dark:hover:text-zinc-100"
        >
            <flux:icon.chevron-left variant="mini" />
        </a>

        <div class="min-w-0">
            <p class="truncate font-bold leading-tight">How this works</p>
            <p class="text-micro text-zinc-500 dark:text-zinc-400">Picks, Tallboys and the rooms</p>
        </div>
    </div>

    {{-- ============================================ THE CURRENCY ======= --}}
    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <div class="flex items-center gap-2">
            <x-tallboy-mark :size="20" />
            <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">Tallboys</flux:subheading>

            @if ($this->balance !== null)
                <span class="tabular ms-auto rounded bg-zinc-100 px-1.5 py-0.5 text-micro font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    You hold {{ $this->balance }}
                </span>
            @endif
        </div>

        <flux:subheading>{{ Voice::line('picks.how.currency') }}</flux:subheading>

        {{-- The two sinks, each with the verb its button wears. --}}
        <ul class="flex flex-col gap-2">
            <li class="flex gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-current opacity-50" aria-hidden="true"></span>
                <span class="min-w-0">
                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">Ice down</span> a Tallboy for a Spotlight seat —
                    {{ App\Enums\LobbyShelf::Spotlight->entryCredits() }} to enter. Every other shelf is free.
                </span>
            </li>
            <li class="flex gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-current opacity-50" aria-hidden="true"></span>
                <span class="min-w-0">
                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">Crush</span> one on a single game:
                    +{{ App\Services\Contests\ModeEngine::TALLBOY_SWING }} if it lands,
                    −{{ App\Services\Contests\ModeEngine::TALLBOY_SWING }} if it does not. One a week, and you can pull it back until kickoff.
                </span>
            </li>
        </ul>
    </div>

    {{-- ============================================== THE COOLER ======= --}}
    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">The cooler holds {{ App\Actions\GrantWalletEntry::COOLER_CAPACITY }}</flux:subheading>
        <flux:subheading>{{ Voice::line('picks.how.cooler') }}</flux:subheading>

        {{-- THE ONE RULE A READER NEEDS TO PLAN A WEEK. Three rows, stacked
             at every width — a three-column table of two numbers and a
             sentence is a table for the sake of being one, and it would
             have to scroll at 390px. The row the reader is standing in is
             marked, which is what turns a rule into an answer. --}}
        <ul class="flex flex-col gap-1.5">
            @foreach ($this->cooler as $tier)
                <li
                    wire:key="cooler-{{ $loop->index }}"
                    @class([
                        'flex items-baseline gap-2 rounded-lg px-2.5 py-1.5 text-sm',
                        'bg-zinc-100 dark:bg-zinc-800/70' => $tier['mine'],
                    ])
                >
                    <span class="tabular w-24 shrink-0 font-semibold text-zinc-900 dark:text-zinc-100">{{ $tier['range'] }}</span>
                    <span class="tabular w-14 shrink-0 font-semibold {{ $tier['credits'] > 0 ? 'text-zinc-900 dark:text-zinc-100' : 'text-zinc-400' }}">
                        {{ $tier['credits'] > 0 ? '+'.$tier['credits'] : 'none' }}
                    </span>
                    <span class="min-w-0 text-zinc-500 dark:text-zinc-400">{{ $tier['plain'] }}</span>
                </li>
            @endforeach
        </ul>

        <p class="text-micro leading-relaxed text-zinc-500">
            Paid once a week, the first time you open Picks. Winning, climbing a rank and hitting a milestone all pay on top.
        </p>
    </div>

    {{-- =============================================== THE ROOMS ======= --}}
    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-0.5">
            <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">What each room costs</flux:subheading>
            <flux:subheading>{{ Voice::line('picks.how.rooms') }}</flux:subheading>
        </div>

        {{-- ONE STACKED CARD PER ROOM, widening to two columns above `sm`.
             NOT a table: a three-column grid of thirteen rooms cannot fit
             390px without scrolling sideways, ChromeConsistencyTest fails
             any view that tries, and the non-negotiables put the design at
             390 first. The two facts are chips so they are scannable down
             the column rather than read as prose. --}}
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($this->rooms as $room)
                <div wire:key="room-rule-{{ $loop->index }}" class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="min-w-0">
                        <p class="truncate font-semibold leading-tight">{{ $room['name'] }}</p>
                        {{-- The House row IS its shelf, and a card saying
                             the same words twice reads as a rendering bug. --}}
                        @if ($room['shelf'] !== $room['name'])
                            <p class="text-micro text-zinc-500 dark:text-zinc-400">{{ $room['shelf'] }}</p>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span @class([
                            'rounded px-1.5 py-0.5 text-micro font-semibold',
                            'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' => $room['entry'] > 0,
                            'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $room['entry'] === 0,
                        ])>
                            {{ $room['entry'] > 0 ? $room['entry'].' '.Str::plural('Tallboy', $room['entry']).' to enter' : 'Free to enter' }}
                        </span>

                        <span @class([
                            'rounded px-1.5 py-0.5 text-micro font-semibold',
                            'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $room['wager'] !== 'No',
                            'bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500' => $room['wager'] === 'No',
                        ])>
                            Wager: {{ $room['wager'] }}
                        </span>
                    </div>

                    {{-- The REASON, always — a "no" with no reason beside it
                         reads as an oversight rather than a rule. --}}
                    <p class="text-micro leading-relaxed text-zinc-500">{{ $room['rule'] }}</p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- =============================================== THE MODES ======= --}}
    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-0.5">
            <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">How it's played</flux:subheading>
            <flux:subheading>{{ Voice::line('lobby.rules.subheading') }}</flux:subheading>
        </div>

        {{-- The SAME card the Lobby uses, reading the SAME ruleLines(): the
             mode is never described two ways, and a second explainer would
             be a second answer waiting to drift. --}}
        @foreach (ContestMode::cases() as $mode)
            <x-mode-rules wire:key="how-rules-{{ $mode->value }}" :mode="$mode" />
        @endforeach

        @include('partials.pickem-laws')
    </div>

    {{-- ============================================ STILL STUCK? ======= --}}
    {{-- The rules page is where a confused reader lands, so the door out of
         confusion sits at its foot — a plain button, because the label is
         the instruction. --}}
    <flux:modal.trigger name="help">
        <flux:button size="sm" variant="ghost" class="self-start">
            <flux:icon.chat-dots variant="micro" />
            {{ App\Support\HelpAnswer::stuckLabel(auth()->user()) }}
        </flux:button>
    </flux:modal.trigger>
</div>
