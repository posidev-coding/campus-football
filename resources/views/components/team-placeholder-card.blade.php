{{--
    The Bandwagon State card — the glance card's shape with none of its facts.

    A SIBLING of x-team-glance-card rather than a flag on it: the glance card
    is a factual surface whose header links to a real team page and whose
    destructure assumes real data, and this one is LOUD chrome whose whole job
    is selling the tap that replaces it. Dashed border — the app's language
    for "not a real thing yet" (the add slot and the Pick'em teaser speak it)
    — plus a badge that says so in a word, because the joke must never let a
    reader mistake 0-99 for a record we are reporting.

    The whole card is a BUTTON that opens the team picker. Never a link:
    Bandwagon State has no page, and a joke that 404s stops being funny.
--}}
<button
    type="button"
    x-on:click="$dispatch('start-onboarding')"
    {{ $attributes->class(['overflow-hidden rounded-xl border border-dashed border-zinc-300 text-left transition-colors hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:border-zinc-600 dark:hover:bg-zinc-900']) }}
>
    <span class="flex items-center gap-3 px-4 py-3">
        {{-- The glance card's white puck, deliberately holding nothing: a
             team this fictitious does not get a mark. --}}
        <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-white shadow-sm ring-1 ring-black/10 dark:bg-transparent dark:shadow-none dark:ring-1 dark:ring-zinc-700">
            <span class="size-9 shrink-0 rounded-full bg-zinc-100 dark:bg-zinc-800"></span>
        </span>

        <span class="min-w-0 flex-1">
            <span class="flex min-w-0 items-baseline gap-1.5">
                <span class="truncate text-lg font-bold leading-tight">{{ App\Support\PlaceholderTeam::LOCATION }}</span>
                <flux:badge size="sm" color="zinc" class="shrink-0">Placeholder</flux:badge>
            </span>

            <span class="block truncate text-sm text-zinc-500 dark:text-zinc-400">
                {{ App\Support\PlaceholderTeam::RECORD }} · {{ App\Support\PlaceholderTeam::STANDING }}
            </span>
        </span>
    </span>

    <span class="flex flex-col gap-2 px-4 pb-3">
        <span class="text-sm text-zinc-600 dark:text-zinc-300">
            {{ App\Support\Voice::line('placeholder.body') }}
        </span>

        {{-- The instruction, plain and separate from the joke — never let
             the joke eat it. --}}
        <span class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 dark:text-blue-400">
            Pick your real team
            <flux:icon name="arrow-right" variant="micro" />
        </span>
    </span>
</button>
