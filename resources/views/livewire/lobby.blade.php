<?php

use App\Actions\JoinGroup;
use App\Enums\ContestMode;
use App\Exceptions\ContestFull;
use App\Exceptions\PickemParticipationGated;
use App\Models\Group;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Cadence;
use App\Support\Lobby;
use App\Support\LobbyCatalog;
use App\Support\Voice;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * THE LOBBY — the contest browser, and only that. Where a reader walks a
 * shelf of open public rooms and takes a seat. Outside the `pickem` flag
 * it keeps the coming-soon promise the Picks tab shipped with, verbatim.
 *
 * Pass 4, 2026-08-20: the personal half moved to MY PICKS. Pass 3's "one
 * zoned scroll" was shaped when the lobby held three rooms — thirteen is
 * exactly the volume that decision said would never exist, and at that
 * size a mixed stack of card species stops being a store.
 *
 * The shape now is a store's: the Saturday pinned to the top in a sticky
 * band, then named shelves of UNIFORM rows. A row opens the room; Join
 * seats you where you stand. Nothing here is about the reader — no picks,
 * no groups, no rank.
 *
 * ONE read of the inventory (App\Support\Lobby::openRooms), projected
 * into shelves. Feasibility is never asked at render time: a catalog
 * entry with no live room is a dashed row, inferred from the sweep's own
 * output, and LobbyCatalog::shelves() is where that rule lives.
 */
new class extends Component
{
    #[Computed]
    public function showLobby(): bool
    {
        return auth()->check() && Feature::active('pickem');
    }

    /**
     * The whole open inventory, INCLUDING rooms the reader already sits
     * in — a seat is not a closed shelf, and only a seat-inclusive read
     * can tell the two apart.
     *
     * @return \Illuminate\Support\Collection<int, Group>
     */
    #[Computed]
    public function openRooms()
    {
        return Lobby::openRooms(auth()->user());
    }

    /**
     * What is actually for sale. The name survives from pass 3 because
     * the tests that pin "only OPEN rooms are listed" are pinned to it.
     *
     * @return \Illuminate\Support\Collection<int, Group>
     */
    #[Computed]
    public function publics()
    {
        return $this->openRooms->reject(fn (Group $room) => Lobby::seated($room))->values();
    }

    /**
     * The Saturday shelves, in LobbyShelf case order, with the dashed
     * rows for shapes this Saturday could not seat.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function shelves(): array
    {
        return LobbyCatalog::shelves($this->openRooms);
    }

    /**
     * The always-open house lobbies, sold after the Saturday shelves: an
     * evergreen table is not a Saturday product and does not belong in a
     * Saturday's shelf.
     *
     * @return \Illuminate\Support\Collection<int, Group>
     */
    #[Computed]
    public function evergreens()
    {
        return $this->publics->reject(fn (Group $room) => $room->isRoom())->values();
    }

    /**
     * The band: which Saturday is being sold, and how many seats are on
     * offer. Week 0 / Week 1 by construction — displayWeekLabel counts
     * the way the fans do — and the week's own date range is never
     * printed, because "AUG 22" is a range nobody is playing.
     *
     * @return array{label: string|null, date: string|null, count: int}
     */
    #[Computed]
    public function weekContext(): array
    {
        $weekId = app(CfbCalendar::class)->defaultWeekId(app(CfbCalendar::class)->currentYear());
        $week = $weekId === null ? null : Week::find($weekId);
        $saturday = $week === null ? null : Cadence::activeSaturday($week);

        return [
            'label' => $week === null ? null : Cadence::displayWeekLabel($week, $saturday),
            'date' => $saturday?->format('D M j'),
            'count' => $this->publics->filter(fn (Group $room) => $room->isRoom())->count(),
        ];
    }

    public function joinLobby(int $groupId, JoinGroup $action)
    {
        $lobby = Group::query()->where('kind', Group::KIND_LOBBY)->findOrFail($groupId);

        return $this->takeSeat($action, $lobby, 'lobbies');
    }

    private function takeSeat(JoinGroup $action, Group $group, string $errorBag)
    {
        try {
            $action->handle(auth()->user(), $group);
        } catch (PickemParticipationGated) {
            $this->addError($errorBag, Voice::line('groups.verify_first'));

            return;
        } catch (ContestFull) {
            // A race to the last seat: the lobby re-renders without the
            // filled room, and the words say why.
            $this->addError($errorBag, Voice::line('contest.room.full'));
            unset($this->openRooms, $this->publics, $this->shelves, $this->evergreens, $this->weekContext);

            return;
        }

        session()->flash('status', Voice::line('groups.joined', ['group' => $group->name]));

        // Each kind lands at its own address — no clubhouse double-hop.
        return $this->redirectRoute(
            $group->isRoom() ? 'pickem.room' : 'pickem.group',
            $group,
            navigate: true,
        );
    }
}; ?>

<div class="flex flex-col gap-6 lg:mx-auto lg:w-full lg:max-w-3xl">
    @if ($this->showLobby)
        {{-- =============================== THE LOBBY ================= --}}
        {{-- The section strip names this place — the h1 stays for screen
             readers only, the house rule. --}}
        <h1 class="sr-only">Lobby</h1>

        {{-- THE SATURDAY, pinned. Which card is being sold was 1,400px
             below the rooms in pass 3, which is the same as not saying
             it. Opaque and with nothing to travel through: the
             container's padding is cancelled and the spacing moved
             inside, so the band does not slide under the header. --}}
        @if ($this->weekContext['label'] !== null)
            {{-- z-30, the ladder's screen-chrome rung: at z-20 the day-
                 heading tier underneath could win the tie and slide OVER
                 the Saturday band. --}}
            <div class="sticky z-30 -mx-4 -mt-5 flex items-baseline justify-between gap-3 bg-white px-4 pt-3 pb-2 top-[var(--chrome-offset)] dark:bg-zinc-950">
                <p class="min-w-0 truncate text-sm font-semibold">
                    {{ $this->weekContext['label'] }}
                    @if ($this->weekContext['date'])
                        <span class="font-normal text-zinc-500 dark:text-zinc-400">· {{ $this->weekContext['date'] }}</span>
                    @endif
                </p>
                <p class="tabular shrink-0 text-micro text-zinc-500 dark:text-zinc-400">
                    {{ $this->weekContext['count'] }} {{ Str::plural('room', $this->weekContext['count']) }} open
                </p>
            </div>
        @endif

        <livewire:verify-callout :body-key="'verify.picks.body'" :dismissable="false" @email-verified="$refresh" />

        @if (session('status'))
            <x-notice tone="success">{{ session('status') }}</x-notice>
        @endif

        @error('lobbies')
            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror

        {{-- THE SHELVES. Plain headings — people navigate by them — with
             the register line underneath, render-guarded. --}}
        @foreach ($this->shelves as $shelf)
            @php $shelfLine = Voice::line($shelf['shelf']->voiceKey()); @endphp

            <div wire:key="shelf-{{ $shelf['shelf']->value }}" class="flex flex-col gap-2">
                <div class="flex flex-col gap-0.5">
                    <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $shelf['shelf']->heading() }}</flux:subheading>
                    @if ($shelfLine !== '')
                        <flux:subheading>{{ $shelfLine }}</flux:subheading>
                    @endif
                </div>

                @foreach ($shelf['rooms'] as $entry)
                    <x-room-row
                        wire:key="room-{{ $entry['room']->id }}"
                        :room="$entry['room']"
                        :mode="$entry['mode']"
                        :game-count="$entry['gameCount']"
                        :seats="$entry['seats']"
                        :seated="$entry['seated']"
                    />
                @endforeach

                {{-- What the Saturday could not seat. Collapsed to ONE
                     muted line per shelf: thirteen catalog shapes with
                     three stocked made a gray wall of dashed rows the
                     first thing an invited user saw. The Conference shelf
                     keeps named rows — its entries are identities a fan
                     scans for, not variants. The $stocked guard upstream
                     still means an empty lobby dashes nothing at all. --}}
                @if ($shelf['closed'] !== [])
                    @if ($shelf['shelf'] === App\Enums\LobbyShelf::Conference)
                        @foreach ($shelf['closed'] as $closed)
                            <div
                                wire:key="closed-{{ $closed['mode']->value }}-{{ $closed['flavor']?->value ?? 'standard' }}"
                                class="flex items-center gap-3 rounded-xl border border-dashed border-zinc-200 px-3 py-2.5 dark:border-zinc-800"
                            >
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-zinc-200 dark:border-zinc-800">
                                    <flux:icon :name="$closed['mode']->icon()" variant="micro" class="size-4 text-zinc-300 dark:text-zinc-600" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium leading-tight text-zinc-400 dark:text-zinc-500">{{ $closed['label'] }}</span>
                                    <span class="block truncate text-micro text-zinc-400 dark:text-zinc-500">Not enough games this Saturday</span>
                                </span>
                            </div>
                        @endforeach
                    @else
                        <p
                            wire:key="closed-line-{{ $shelf['shelf']->value }}"
                            class="text-micro text-zinc-400 dark:text-zinc-500"
                        >
                            {{ Voice::line('lobby.shelf.also', ['list' => collect($shelf['closed'])->pluck('label')->implode(' · ')]) }}
                        </p>
                    @endif
                @endif
            </div>
        @endforeach

        {{-- An honestly empty store, when there is nothing at all. --}}
        @if ($this->shelves === [] && $this->evergreens->isEmpty())
            <flux:callout icon="user-group">
                <flux:callout.heading>No open rooms right now</flux:callout.heading>
                <flux:callout.text>{{ Voice::line('lobby.publics.empty') }}</flux:callout.text>
            </flux:callout>
        @endif

        {{-- The always-open tables, after the Saturday. --}}
        @if ($this->evergreens->isNotEmpty())
            <div class="flex flex-col gap-2">
                <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">Always open</flux:subheading>

                @foreach ($this->evergreens as $lobby)
                    <x-room-row
                        wire:key="lobby-{{ $lobby->id }}"
                        :room="$lobby"
                        :mode="$lobby->contests->first()?->mode ?? ContestMode::Classic"
                        :seats="$lobby->memberships_count"
                    />
                @endforeach
            </div>
        @endif

        {{-- The other way to play, one line: rooms are the house's, a
             group is yours. --}}
        <x-link-row :href="route('pickem.create')" title="Rather run your own?">
            <span class="block pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Start a group — name it, pick its mode, send one link.</span>
        </x-link-row>

        {{-- The rules, one expandable card per mode — the same
             ruleLines() the docs and the mode doors read. --}}
        <div class="flex flex-col gap-2">
            <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">How it's played</flux:subheading>
            <flux:subheading>{{ Voice::line('lobby.rules.subheading') }}</flux:subheading>

            <div class="flex flex-col gap-2 pt-1">
                @foreach (ContestMode::cases() as $mode)
                    <x-mode-rules wire:key="rules-{{ $mode->value }}" :mode="$mode" />
                @endforeach
            </div>

            {{-- The rules every mode shares, stated once and plainly. --}}
            <p class="pt-1 text-micro leading-relaxed text-zinc-500">
                Every pick is against the spread, and every line is a half point — no pushes, ever.
                Picks lock game by game at kickoff. Commissioner slates are due Tuesday night;
                weeks turn official Sunday noon. Tied weeks share the win.
            </p>
        </div>
    @else
        @include('partials.pickem-promise')
    @endif
</div>
