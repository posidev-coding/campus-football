{{--
    THE COMING-SOON PROMISE — what a guest, or anyone outside the `pickem`
    flag, sees at BOTH pick'em front doors. It shipped as the Picks tab's
    whole screen and is kept verbatim: a promise, not data.

    Included rather than redirected on purpose. /picks and /lobby both stay
    real 200s for everyone, because a redirect between them would be a 301
    a dev browser caches forever.
--}}
<div class="flex items-center gap-2">
    <x-brand.mark class="size-6 shrink-0 sm:hidden" />
    <flux:heading size="xl">Pick'em</flux:heading>
    <flux:badge size="sm" color="zinc">Coming soon</flux:badge>
</div>

<flux:subheading>{{ \App\Support\Voice::line('picks.screen.pitch') }}</flux:subheading>

<x-verify-email-callout :body-key="'verify.picks.body'" :dismissable="false" />

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

<p class="text-stat text-zinc-500 dark:text-zinc-400">
    Until then, <a href="{{ route('home') }}" wire:navigate class="font-medium text-blue-600 hover:underline dark:text-blue-400">follow your teams</a> — your picks will start from the teams you already watch.
</p>
