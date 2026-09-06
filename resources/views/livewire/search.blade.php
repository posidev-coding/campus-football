<?php

use App\Livewire\Concerns\RecordsSearches;
use App\Support\Search;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The ⌘K command palette.
 *
 * Desktop-only: on a phone, the bar at the top of Home expands into the
 * full-screen panel instead. All three surfaces read App\Support\Search, so
 * they can never drift on WHAT is found — this one renders flux:command.item
 * rows rather than the shared partial because arrow-key navigation is the
 * point of a palette, and that is what command items provide.
 */
new class extends Component
{
    use RecordsSearches;

    public string $q = '';

    #[Computed]
    public function teams()
    {
        return Search::teams($this->q);
    }

    #[Computed]
    public function players()
    {
        return Search::players($this->q);
    }

    #[Computed]
    public function coaches()
    {
        return Search::coaches($this->q);
    }

    #[Computed]
    public function conferences()
    {
        return Search::conferences($this->q);
    }

    #[Computed]
    public function games()
    {
        return Search::games($this->q);
    }

    #[Computed]
    public function hasResults(): bool
    {
        return $this->teams->isNotEmpty()
            || $this->players->isNotEmpty()
            || $this->coaches->isNotEmpty()
            || $this->conferences->isNotEmpty()
            || $this->games->isNotEmpty();
    }
}; ?>

{{--
    `flex items-center`, not a bare div: `<ui-modal>` below is an inline custom
    element, so in a block root it opens a line box whose strut adds descender
    space beneath the trigger. The root then measures taller than the trigger,
    and the header cluster's `items-center` centres that taller box — which is
    what left the icon sitting a couple of pixels above the avatar beside it. A
    flex container has no line box, so what the header aligns is the trigger's
    own box.

    The trigger opens on FOCUS, so it has to hand focus off before it opens: a
    native `<dialog>` returns focus to whatever held it when `showModal()` ran,
    so a trigger that still had focus would be re-focused on close and reopen
    the modal forever — Escape included, since the dialog's `close` event fires
    in a later task than the focus restore and cannot be used to suppress it.
    Blurring first makes `<body>` the element focus returns to.

    `show()` also refuses to open an already-open dialog. `showModal()` throws
    InvalidStateError on one, and click alone could reach it twice: a mouse
    click focuses the button BEFORE it fires the click.
--}}
<div
    class="flex items-center"
    x-data="{
        mac: /Mac|iPhone|iPad/.test(navigator.userAgent),

        show() {
            const dialog = this.$root.querySelector('dialog')

            if (dialog?.open) { return }

            document.activeElement?.blur()

            this.$dispatch('modal-show', { name: 'search' })
        },
    }"
    x-on:keydown.cmd.k.document="$event.preventDefault(); show()"
    x-on:keydown.ctrl.k.document="$event.preventDefault(); show()"
>
    {{-- An icon button on a tablet, a search FIELD from `lg` — the same
         control, restyled, never a second one. It wears `flux:input size="sm"`'s
         own geometry (h-8, rounded-lg, the outline border and shadow) so the
         desktop header and the phone's Home bar read as one object at two
         widths, and it is a button rather than a readonly input because
         nothing is ever typed here: the field it looks like lives in the
         modal, and "read-only edit text" is not what a screen reader should
         announce for something that opens a dialog. --}}
    <button
        type="button"
        x-on:click="show()"
        x-on:focus="show()"
        aria-haspopup="dialog"
        aria-keyshortcuts="Meta+K Control+K"
        class="flex size-8 shrink-0 items-center justify-center rounded-md whitespace-nowrap text-zinc-800 transition-colors hover:bg-zinc-800/5 lg:w-64 lg:justify-start lg:gap-2 lg:rounded-lg lg:border lg:border-zinc-200 lg:border-b-zinc-300/80 lg:bg-white lg:ps-3 lg:pe-2 lg:text-sm lg:text-zinc-400 lg:shadow-xs lg:hover:border-zinc-300 lg:hover:bg-white dark:text-white dark:hover:bg-white/15 dark:lg:border-white/10 dark:lg:bg-white/10 dark:lg:text-zinc-400 dark:lg:shadow-none dark:lg:hover:border-white/20 dark:lg:hover:bg-white/10"
    >
        <flux:icon.magnifying-glass variant="mini" class="size-5 shrink-0 lg:size-4" />

        {{-- Below `lg` the label is the accessible name and nothing else; from
             `lg` it is the placeholder. Never an aria-label over the visible
             text — an accessible name that does not contain what is on screen
             is what breaks voice control. --}}
        <span class="sr-only lg:hidden">Search</span>
        <span class="hidden lg:inline">Search teams, players…</span>

        {{-- The shortcut has been ⌘K all along with nothing to say so. Bound
             on Ctrl too now that the hint is on screen, because a Windows
             reader cannot press ⌘ and a hint they cannot use is worse than
             none. --}}
        <kbd
            x-text="mac ? '⌘K' : 'Ctrl K'"
            class="ms-auto hidden shrink-0 rounded border border-zinc-200 px-1 pb-px font-sans text-micro text-zinc-400 lg:inline-block dark:border-white/10 dark:text-zinc-500"
        >⌘K</kbd>
    </button>

    <flux:modal name="search" variant="bare" class="my-[10vh] max-h-screen w-full max-w-[32rem] overflow-y-hidden">
        <flux:command class="inline-flex max-h-[70vh] flex-col border-none shadow-lg">
            <flux:command.input
                wire:model.live.debounce.300ms="q"
                placeholder="Search teams, players, coaches, games…"
                closable
            />

            <flux:command.items>
                @if ($this->teams->isNotEmpty())
                    <x-search-heading>Teams</x-search-heading>

                    @foreach ($this->teams as $team)
                        <flux:command.item
                            href="{{ route('team', $team) }}"
                            wire:navigate
                            wire:key="s-team-{{ $team->id }}"
                        >
                            <span class="flex items-center gap-2">
                                <x-team-logo :team="$team" size="sm" />
                                {{ $team->display_name }}
                            </span>
                        </flux:command.item>
                    @endforeach
                @endif

                @if ($this->players->isNotEmpty())
                    <x-search-heading>Players</x-search-heading>

                    @foreach ($this->players as $athlete)
                        <flux:command.item
                            href="{{ route('player', $athlete) }}"
                            wire:navigate
                            icon="user"
                            wire:key="s-player-{{ $athlete->id }}"
                        >{{ $athlete->display_name }}</flux:command.item>
                    @endforeach
                @endif

                @if ($this->coaches->isNotEmpty())
                    <x-search-heading>Coaches</x-search-heading>

                    @foreach ($this->coaches as $coach)
                        <flux:command.item
                            href="{{ route('coach', $coach) }}"
                            wire:navigate
                            icon="academic-cap"
                            wire:key="s-coach-{{ $coach->id }}"
                        >{{ $coach->display_name }}</flux:command.item>
                    @endforeach
                @endif

                @if ($this->conferences->isNotEmpty())
                    <x-search-heading>Conferences</x-search-heading>

                    @foreach ($this->conferences as $conference)
                        <flux:command.item
                            href="{{ route('conference', $conference) }}"
                            wire:navigate
                            icon="trophy"
                            wire:key="s-conf-{{ $conference->id }}"
                        >{{ $conference->name }}</flux:command.item>
                    @endforeach
                @endif

                @if ($this->games->isNotEmpty())
                    <x-search-heading>Games</x-search-heading>

                    @foreach ($this->games as $game)
                        <flux:command.item
                            href="{{ route('game', $game) }}"
                            wire:navigate
                            icon="calendar-days"
                            wire:key="s-game-{{ $game->id }}"
                        >{{ $game->name }}</flux:command.item>
                    @endforeach
                @endif

                @if (! $this->hasResults)
                    <div class="px-3 py-6 text-center text-sm text-zinc-500">
                        {{ App\Support\Search::tooShort($q) ? 'Type at least two characters.' : 'Nothing found for that.' }}
                    </div>
                @endif
            </flux:command.items>
        </flux:command>
    </flux:modal>
</div>
