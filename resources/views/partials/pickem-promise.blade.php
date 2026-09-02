{{--
    THE COMING-SOON PROMISE — what a guest, or anyone outside the `pickem`
    flag, sees at BOTH pick'em front doors. It shipped as the Picks tab's
    whole screen and is kept verbatim: a promise, not data.

    Included rather than redirected on purpose. /picks and /lobby both stay
    real 200s for everyone, because a redirect between them would be a 301
    a dev browser caches forever.

    TWO STATES, one partial. Outside the flag it is the promise, verbatim.
    With the flag OPEN the only reader who lands here is a GUEST on the
    Picks tab (the Lobby shows a guest its rooms), and "Coming soon" over a
    product that is live is the launch-day bug this partial shipped
    (2026-09-02). So an open flag turns the badge off, pitches the real
    thing in the app invite's words, and offers the one door a guest has:
    an account, with this screen as the way back (the host's start()).

    The open state reads the CONFIG mirror, never Pennant: a guest is the
    null scope, and a persisted null-scope row would keep "closed" until
    purged — the trap PickemPreflight documents.
--}}
@php $open = config('cfb.pickem_open') === true; @endphp

<div class="flex items-center gap-2">
    <x-brand.mark class="size-6 shrink-0 sm:hidden" />
    <flux:heading size="xl">Pick'em</flux:heading>
    @unless ($open)
        <flux:badge size="sm" color="zinc">Coming soon</flux:badge>
    @endunless
</div>

<flux:subheading>{{ \App\Support\Voice::line($open ? 'join.app.body' : 'picks.screen.pitch') }}</flux:subheading>

@if ($open)
    <div class="flex flex-wrap items-center gap-2">
        <flux:button wire:click="start" variant="primary">Create your account</flux:button>
        <flux:button :href="route('pickem.lobby')" wire:navigate>Browse the Lobby</flux:button>
    </div>
    <p class="text-micro text-zinc-500">
        Already have one? <a href="{{ route('login') }}" wire:navigate class="font-medium text-blue-600 hover:underline dark:text-blue-400">Sign in</a>.
    </p>
@endif

<livewire:verify-callout :body-key="'verify.picks.body'" :dismissable="false" @email-verified="$refresh" />

<div class="flex flex-col gap-3">
    <div class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700">
        <div class="flex items-center gap-2">
            <flux:icon name="clipboard-document-check" variant="mini" class="text-zinc-400" />
            <span class="font-semibold">Weekly slates</span>
        </div>
        <p class="pt-1 text-sm text-zinc-500 dark:text-zinc-400">
            A week's games, your calls, locked at kickoff — every pick against the spread.
        </p>
    </div>

    <div class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700">
        <div class="flex items-center gap-2">
            <flux:icon name="user-group" variant="mini" class="text-zinc-400" />
            <span class="font-semibold">Groups</span>
        </div>
        <p class="pt-1 text-sm text-zinc-500 dark:text-zinc-400">
            A private leaderboard for your people — invites, standings, and a commissioner.
        </p>
    </div>

    <div class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700">
        <div class="flex items-center gap-2">
            <flux:icon name="chart-bar" variant="mini" class="text-zinc-400" />
            <span class="font-semibold">Season-long records</span>
        </div>
        <p class="pt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Every pick kept, every week counted — a full season of results by the end.
        </p>
    </div>
</div>

@unless ($open)
    <p class="text-stat text-zinc-500 dark:text-zinc-400">
        Until then, <a href="{{ route('home') }}" wire:navigate class="font-medium text-blue-600 hover:underline dark:text-blue-400">follow your teams</a> — your picks will start from the teams you already watch.
    </p>
@endunless
