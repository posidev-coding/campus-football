{{--
    The gamification shelf: Tallboy balance, rank and XP, as chips.

    The balance and XP are REAL — summed from the wallet_entries ledger
    (User::walletTotals(), one memoized query for both render sites) — and so
    is the RANK now: App\Support\RankLadder turns the XP total into a rung
    (Walk-On through Legend). It is a pure computation over the number
    already in hand, not a second query and not a stored column, so the chip
    cannot disagree with the ledger beside it. Both chips open the Picks
    screen, which says "Coming soon" out loud outside the flag, so the
    numbers never pretend the game is live before it is.

    This file is THE seam for the currency's NAME, and x-tallboy-mark is the
    seam for its ART — split when the wager gave the mark a second render
    site, because a seam with two copies is not a seam. If App Store review
    ever reads the can as alcohol imagery (roadmap Phase 7 carries the
    contingency), the swap happens across those two files and nowhere else.
    The LEDGER is why it stays cheap: the column is `credits`, deliberately
    neutral, so renaming the product never touches data.

    They are TALLBOYS, and drinking vocabulary is allowed for the 2026 season
    (.ai/rules/actions.md, waived deliberately) — the mark was always a can
    and only the label said latte.

    The mark rides x-tallboy-mark, which owns the light/dark swap and the
    chip cut. 18px here is the size that cut was drawn for.

    Rendered only for signed-in users (a balance is YOURS) in two homes that
    never overlap: x-home-nav's reserved slot below `sm`, the layout header
    from `sm`. Both carry `data-tour="wallet"` from the call site, so the tour
    spotlights whichever is visible — the search step's two-surfaces pattern.
--}}
@php
    $wallet = auth()->user()->walletTotals();
    $rank = App\Support\RankLadder::name($wallet['xp']);
@endphp

<div {{ $attributes->class(['flex items-center gap-0.5']) }}>
    <a
        href="{{ route('pickem.home') }}"
        wire:navigate
        class="flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
        aria-label="{{ $wallet['credits'] }} {{ str('Tallboy')->plural($wallet['credits']) }} — earning starts with Pick'em"
    >
        <x-tallboy-mark :size="18" />
        <span>{{ $wallet['credits'] }}</span>
    </a>

    <a
        href="{{ route('pickem.home') }}"
        wire:navigate
        class="flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
        aria-label="{{ $rank }} rank, {{ $wallet['xp'] }} XP"
    >
        <span>{{ $rank }}</span>
        <span class="rounded bg-zinc-100 px-1 py-px text-micro font-semibold text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $wallet['xp'] }} XP</span>
    </a>
</div>
