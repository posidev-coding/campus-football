<?php

use App\Models\Group;
use Livewire\Component;

/**
 * GROUP TALK — the room's thread as a DESTINATION, not a drawer and not
 * a tab. Approved 2026-08-30 (docs/plans/home-and-picks-pass.md) after
 * Task D removed the foot embed from the clubhouse: the pick surface
 * stays chat-free, and the talk gets a room of its own one tap away.
 * One address serves both kinds — the doors render only inside a
 * clubhouse, so the kind is resolved before anyone arrives here.
 *
 * Members only, both kinds: reading a game's or team's thread is public,
 * but this thread belongs to the people in it. Every write gate stays in
 * PostToConversation, exactly where it has always been.
 */
new class extends Component
{
    public Group $group;

    public function mount(Group $group): void
    {
        $this->group = $group;

        abort_unless(
            auth()->check() && $group->memberships()->where('user_id', auth()->id())->exists(),
            403,
        );
    }
}; ?>

<div class="flex flex-col gap-5 md:mx-auto md:w-full md:max-w-3xl">
    <h1 class="sr-only">{{ $group->name }} talk</h1>

    {{-- The slim band: whose thread this is, and the way back to the
         picks. Not the full hero — Talk is a side room, not the house. --}}
    <div class="flex items-center gap-3">
        <a
            href="{{ $group->isRoom() ? route('pickem.room', $group) : route('pickem.group', $group) }}"
            wire:navigate
            aria-label="Back to {{ $group->name }}"
            class="focus-ring flex size-9 shrink-0 items-center justify-center rounded-lg border border-zinc-200 text-zinc-500 transition-colors hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-700 dark:hover:border-zinc-600 dark:hover:text-zinc-100"
        >
            <flux:icon.chevron-left variant="mini" />
        </a>

        <div class="min-w-0">
            <p class="truncate font-bold leading-tight">{{ $group->name }}</p>
            <p class="text-micro text-zinc-500 dark:text-zinc-400">{{ $group->isRoom() ? 'Room talk' : 'Group talk' }}</p>
        </div>
    </div>

    <livewire:conversation :topic="$group" :key="'talk-group-'.$group->id" />
</div>
