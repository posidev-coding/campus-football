{{--
    The gamification shelf: Beast Latte balance, rank and XP, as chips.

    The balance and XP are REAL now — summed from the wallet_entries ledger
    (User::walletTotals(), one memoized query for both render sites) — because
    verification pays out and the onboarding moment seeds 25 XP. The rank is
    still the literal starting "Rookie": the ladder is Phase 7's to define,
    and a rank computed from nothing would be a default standing in for
    missing data. Both chips open the Picks screen, which says "Coming soon"
    out loud, so the numbers never pretend the game is live before it is.

    This file is THE seam for the currency: the only place in the app that
    knows its name or its art. If App Store review ever reads the can as
    alcohol imagery (roadmap Phase 9 carries the contingency), the swap — art,
    name, or a per-user variant — happens here and nowhere else. In-app copy
    never uses drinking vocabulary: they are Beast Lattes, the app's currency,
    full stop.

    The can mark ships in a light and a dark cut (the label band flips
    contrast), swapped the same way x-team-logo swaps its marks. The `*-16`
    art is the simplified cut — below 24px the reflection and base rim are
    mud, per the asset README.

    Rendered only for signed-in users (a balance is YOURS) in two homes that
    never overlap: x-home-nav's reserved slot below `sm`, the layout header
    from `sm`. Both carry `data-tour="wallet"` from the call site, so the tour
    spotlights whichever is visible — the search step's two-surfaces pattern.
--}}
@php $wallet = auth()->user()->walletTotals(); @endphp

<div {{ $attributes->class(['flex items-center gap-0.5']) }}>
    <a
        href="{{ route('picks') }}"
        wire:navigate
        class="flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
        aria-label="{{ $wallet['lattes'] }} Beast {{ str('Latte')->plural($wallet['lattes']) }} — earning starts with Pick'em"
    >
        <img src="{{ asset('brand/currency/svg/beast-latte-light-16.svg') }}" alt="" class="h-[18px] w-auto dark:hidden">
        <img src="{{ asset('brand/currency/svg/beast-latte-dark-16.svg') }}" alt="" class="hidden h-[18px] w-auto dark:block">
        <span>{{ $wallet['lattes'] }}</span>
    </a>

    <a
        href="{{ route('picks') }}"
        wire:navigate
        class="flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
        aria-label="Rookie rank, {{ $wallet['xp'] }} XP — earning starts with Pick'em"
    >
        <span>Rookie</span>
        <span class="rounded bg-zinc-100 px-1 py-px text-micro font-semibold text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $wallet['xp'] }} XP</span>
    </a>
</div>
