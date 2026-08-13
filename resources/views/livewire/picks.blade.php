<?php

use Livewire\Component;

/**
 * Pick'em's front door, shipped ahead of Pick'em itself.
 *
 * The fifth tab needed a destination the moment it appeared, and this screen
 * is that destination: it says what the tab becomes and holds the seat. When
 * Pick'em ships (roadmap Phase 5) this file becomes its entry point and the
 * promise cards below turn into the real thing.
 *
 * Empty class on purpose — there is nothing to load. A "coming soon" screen
 * that queries anything is promising with someone else's budget.
 */
new class extends Component {}; ?>

{{-- A promise, not data: centered and held to a readable measure from `lg`,
     the same call get-app makes. Nothing is hidden by the narrowing, so the
     additive-breakpoints rule holds. --}}
<div class="flex flex-col gap-5 lg:mx-auto lg:w-full lg:max-w-3xl">
    {{-- Like Scores and Get-app, no section strip names this screen — the
         Picks area has one screen and therefore no sections — so it is
         allowed its visible heading. The nav label is "Picks"; the product
         name "Pick'em" lives here, the way the teaser card already teaches
         it. The mark retires at `sm` where the header's lockup takes over. --}}
    <div class="flex items-center gap-2">
        <x-brand.mark class="size-6 shrink-0 sm:hidden" />
        <flux:heading size="xl">Pick'em</flux:heading>
        <flux:badge size="sm" color="zinc">Coming soon</flux:badge>
    </div>

    <flux:subheading>{{ App\Support\Voice::line('picks.screen.pitch') }}</flux:subheading>

    {{-- The one gate verification actually holds, explained AT the gate — and
         not dismissable here, because an explanation you can dismiss becomes a
         mystery. Renders nothing for guests and the verified. --}}
    <x-verify-email-callout :body-key="'verify.picks.body'" :dismissable="false" />

    {{-- Dashed borders: the app's established language for "not a real thing
         yet" (the Home teaser and the add-a-team slot speak it already). The
         descriptions stay plain — these are promises about what ships, and
         the voice stays out of promises. --}}
    <div class="flex flex-col gap-3">
        <div class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700">
            <div class="flex items-center gap-2">
                <flux:icon name="clipboard-document-check" variant="mini" class="text-zinc-400" />
                <span class="font-semibold">Weekly slates</span>
            </div>
            <p class="pt-1 text-sm text-zinc-500 dark:text-zinc-400">
                A week's games, your calls, locked at kickoff. Straight up or against the spread.
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

    {{-- The one factual line: where this screen sits in the build. Follows
         are live today, so point the reader at the thing they CAN do. --}}
    <p class="text-stat text-zinc-500 dark:text-zinc-400">
        Until then, <a href="{{ route('home') }}" wire:navigate class="font-medium text-blue-600 hover:underline dark:text-blue-400">follow your teams</a> — your picks will start from the teams you already watch.
    </p>
</div>
