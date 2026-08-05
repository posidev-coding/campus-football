@props([
    'heading',
    'games',
    // Muted text beside the heading. Carries the date for a pinned group, which
    // has been lifted out of the chronology and would otherwise only say a
    // kickoff time — "3:30pm" does not tell you which day.
    'meta' => null,
    'pinned' => false,
    // Marks the team the user ranked FIRST, not every followed team. Marking
    // all of them would spend the only ranking signal the block has on saying
    // "followed", which the reader can already see from the position.
    'lead' => false,
])

{{--
    One day's worth of the slate: a sticky heading and the cards under it.

    Shared by the ordinary day groups and by the pinned followed-team groups,
    so the two cannot drift apart — the sticky offset, the opaque background
    and the z-index are decided once here rather than per caller.
--}}
<div
    {{ $attributes->class(['flex flex-col gap-2']) }}
    @if ($pinned) data-pinned="true" @endif
>
    {{-- Fully opaque, not a translucent blur. A half-transparent heading with
         game cards sliding under it was genuinely hard to read — backdrop-blur
         softens the text behind it but does not stop it competing. The negative
         margin lets the background span the full width so nothing shows through
         at the edges.

         `z-20` is load-bearing, and an opaque background alone is not enough
         without it. A game card's inner wrapper is `relative` with
         `z-index: auto`, which does NOT open a stacking context, so the team
         rows inside it keep their own `z-10` in the ROOT context — tying with
         this heading and winning on tree order, because they come later. Team
         names painted straight over the background and it read as though there
         were none. The ladder is chrome 30, day heading 20, card contents 10. --}}
    <flux:subheading
        class="sticky z-20 -mx-4 flex min-w-0 items-center gap-1.5 bg-white px-4 py-1.5 dark:bg-zinc-950"
        style="top: var(--scores-chrome, 0px)"
    >
        @if ($lead)
            <flux:icon.pin-angle-fill variant="micro" class="shrink-0 text-blue-500" />
        @endif

        <span @class(['truncate', 'font-semibold text-zinc-900 dark:text-zinc-100' => $pinned])>
            {{ $heading }}
        </span>

        @if ($meta)
            <span class="truncate text-micro text-zinc-400 dark:text-zinc-500">{{ $meta }}</span>
        @endif
    </flux:subheading>

    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($games as $game)
            <x-game-card :game="$game" wire:key="game-{{ $game->id }}" />
        @endforeach
    </div>
</div>
