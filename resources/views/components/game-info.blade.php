@props(['game', 'attendance' => null])

{{--
    The facts about the fixture itself — when, where, and how to watch it.

    Deliberately unheaded: a card holding a date, a stadium and a broadcaster
    does not need a label saying so, and "Game Information" would be the
    widest thing in it.

    This replaced a single micro-sized caption line under the tab strip that
    ran "Week 1 · 2026 · Aviva Stadium · Dublin · ESPN · 67,227 attended" —
    five unrelated facts separated by interpuncts, which is a list pretending
    to be a sentence.
--}}
@php
    $kickoff = $game->kickoff_at->setTimezone(config('cfb.timezone'));
    $venue = $game->venue;
@endphp

<div {{ $attributes->class(['flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-800']) }}>
    <div class="flex items-center gap-3 px-3 py-2.5">
        <flux:icon.calendar-days variant="mini" class="shrink-0 text-zinc-400" />

        <div class="flex min-w-0 flex-col">
            <span class="text-sm font-semibold">
                {{ $kickoff->format('g:i A, F j, Y') }}
            </span>

            @if ($game->week)
                <span class="text-micro text-zinc-500">
                    {{ $game->week->name }}@if ($game->season) · {{ $game->season->year }}@endif
                </span>
            @endif
        </div>
    </div>

    @if ($venue)
        <div class="flex items-start gap-3 border-t border-zinc-100 px-3 py-2.5 dark:border-zinc-800/60">
            <flux:icon.map-pin variant="mini" class="mt-0.5 shrink-0 text-zinc-400" />

            <div class="flex min-w-0 flex-col">
                <span class="truncate text-sm font-semibold">{{ $venue->name }}</span>

                @if ($venue->place())
                    <span class="truncate text-micro text-zinc-500">{{ $venue->place() }}</span>
                @endif
            </div>
        </div>

        {{-- 149 of 242 venues have a photo on ESPN's CDN, so this is a bonus
             rather than the card's structure — everything above and below
             reads correctly without it. --}}
        @if ($venue->image_url)
            <img
                src="{{ $venue->image_url }}"
                alt="{{ $venue->name }}"
                loading="lazy"
                class="mx-3 mb-1 h-40 w-[calc(100%-1.5rem)] rounded-md object-cover"
            >
        @endif
    @endif

    @if ($game->broadcasts)
        <div class="flex items-center gap-3 border-t border-zinc-100 px-3 py-2.5 dark:border-zinc-800/60">
            <flux:icon.tv variant="mini" class="shrink-0 text-zinc-400" />

            <div class="flex min-w-0 flex-col gap-1">
                <span class="text-sm font-semibold">Where to Watch</span>

                <div class="flex flex-wrap gap-1">
                    @foreach ($game->broadcasts as $network)
                        <span class="rounded border border-zinc-200 px-1.5 py-0.5 text-micro font-medium text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
                            {{ $network }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if ($attendance)
        <div class="flex items-center gap-3 border-t border-zinc-100 px-3 py-2.5 dark:border-zinc-800/60">
            <flux:icon.users variant="mini" class="shrink-0 text-zinc-400" />

            <div class="flex min-w-0 flex-col">
                <span class="text-sm font-semibold">{{ number_format($attendance) }}</span>
                <span class="text-micro text-zinc-500">Attendance</span>
            </div>
        </div>
    @endif
</div>
