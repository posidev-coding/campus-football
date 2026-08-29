<?php

use App\Actions\JoinGroup;
use App\Enums\ContestMode;
use App\Enums\LobbyShelf;
use App\Exceptions\ContestFull;
use App\Exceptions\PickemParticipationGated;
use App\Models\Group;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Brand;
use App\Support\Cadence;
use App\Support\Lobby;
use App\Support\LobbyCatalog;
use App\Support\Voice;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
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
    /**
     * WHICH SHELF is on show: 'all' plus the four LobbyShelf values. A
     * FILTER, not a split — the default hides nothing, so a reader who
     * never touches the tabs sees the store exactly as it shipped, and a
     * tab narrows it to one kind of room.
     */
    #[Url(except: 'all')]
    public string $view = 'all';

    public function mount(): void
    {
        $this->view = $this->normalizedView($this->view);
    }

    /** #[Url] hydrates without firing this hook, hence mount() normalizes too. */
    public function updatedView(string $value): void
    {
        $this->view = $this->normalizedView($value);
    }

    /** An unknown shelf is the whole store, never an error. */
    private function normalizedView(string $view): string
    {
        return LobbyShelf::tryFrom($view)?->value ?? 'all';
    }

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
     * The shelves THIS TAB shows — a pure filter of shelves(), so
     * openRooms stays the one inventory read and a tab is a lens on it
     * rather than a second query. A shelf tab carries that shelf's open
     * rows and its own closed/dashed content, because "what else stocks
     * here" is a fact about the shelf you are standing at.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function visibleShelves(): array
    {
        if ($this->activeView === 'all') {
            return $this->shelves;
        }

        return array_values(array_filter(
            $this->shelves,
            fn (array $shelf) => $shelf['shelf']->value === $this->activeView,
        ));
    }

    /**
     * The tab actually in force. With no Saturday shelves there is no tab
     * ROW either, so a filter carried in on a stale URL would leave a
     * reader looking at a store that renders nothing, says nothing, and
     * offers no control to undo it — inert is the honest reading.
     */
    #[Computed]
    public function activeView(): string
    {
        return $this->shelves === [] ? 'all' : $this->view;
    }

    /**
     * Does the tab in force have anything OPEN on it? A shelf whose whole
     * stock is dashed rows is, to a reader, an empty shelf: the dashed
     * line says what could stock here on a better Saturday, never what
     * they can play today.
     */
    #[Computed]
    public function tabHasRooms(): bool
    {
        foreach ($this->visibleShelves as $shelf) {
            if ($shelf['rooms'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The subtab row: All, then the four shelves in case order. The set
     * is FIXED rather than inventory-shaped — a tab that appears and
     * vanishes with the Saturday's stock is a control nobody can learn,
     * and an empty tab has an honest line of its own.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function viewTabs(): array
    {
        $tabs = ['all' => 'All'];

        foreach (LobbyShelf::cases() as $shelf) {
            $tabs[$shelf->value] = $shelf->tabLabel();
        }

        return $tabs;
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

    /**
     * The reader's OWN invite link — codeless, so it carries nothing but
     * who is asking. `array_filter` because a handle is optional: an
     * uncredited link still opens the pitch, it just cannot say who sent
     * it, and that is better than a `?by=` with nothing after it.
     */
    #[Computed]
    public function inviteUrl(): string
    {
        return route('pickem.join', array_filter(['by' => auth()->user()?->handle]));
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
            unset($this->openRooms, $this->publics, $this->shelves, $this->visibleShelves, $this->tabHasRooms, $this->evergreens, $this->weekContext);

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
            <div class="sticky z-30 -mx-4 -mt-5 flex flex-col gap-2 bg-white px-4 pt-3 pb-2 top-[var(--chrome-offset)] dark:bg-zinc-950">
                <div class="flex items-baseline justify-between gap-3">
                    <p class="min-w-0 truncate text-sm font-semibold">
                        {{ $this->weekContext['label'] }}
                        @if ($this->weekContext['date'])
                            <span class="font-normal text-zinc-500 dark:text-zinc-400">· {{ $this->weekContext['date'] }}</span>
                        @endif
                    </p>
                    {{-- The count stays GLOBAL — it answers "is there anything
                         on this Saturday", which a filtered tab must not
                         re-answer downward. --}}
                    <p class="tabular shrink-0 text-micro text-zinc-500 dark:text-zinc-400">
                        {{ $this->weekContext['count'] }} {{ Str::plural('room', $this->weekContext['count']) }} open
                    </p>
                </div>

                {{-- WHICH KIND OF ROOM, inside the band on purpose: the band
                     is the one sticky block on this screen, so the filter
                     travels with the Saturday it filters and stays reachable
                     mid-scroll. A second sticky block would be something for
                     this one to slide under. --}}
                @if ($this->shelves !== [])
                    <x-gutter-tabs
                        :items="$this->viewTabs"
                        :selected="$this->activeView"
                        model="view"
                        label="Room type"
                        key-prefix="lobby-view"
                    />
                @endif
            </div>
        @endif

        {{-- WHAT THIS STORE SELLS. Under the band, never above it: the
             band is sticky with its container's padding cancelled and
             nothing to travel through, and anything inserted ahead of it
             is something for it to slide under.

             The sentence is an INSTRUCTION and stays plain in every
             register — public, and one Saturday each are the two facts a
             reader needs to tell a room from a group. The zinger under
             it is where the voice goes, and where the other half of the
             product is pointed at. --}}
        <div class="flex flex-col gap-0.5">
            <flux:subheading>Public rooms — anyone can take a seat, and each one plays a single Saturday.</flux:subheading>
            @php $intro = Voice::line('lobby.intro.zinger'); @endphp
            @if ($intro !== '')
                <p class="text-micro text-zinc-400 dark:text-zinc-500">{{ $intro }}</p>
            @endif
        </div>

        <livewire:verify-callout :body-key="'verify.picks.body'" :dismissable="false" @email-verified="$refresh" />

        @if (session('status'))
            <x-notice tone="success">{{ session('status') }}</x-notice>
        @endif

        @error('lobbies')
            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror

        {{-- THE SHELVES. Plain headings — people navigate by them — with
             the register line underneath, render-guarded. --}}
        @foreach ($this->visibleShelves as $shelf)
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

        {{-- A tab with nothing OPEN on it. The tab set is fixed, so a
             shelf this Saturday could not stock is still a tab, and its
             dashed rows above say what could stock there — which is not
             something anybody can play today. The line carries the way
             back out, in every register. --}}
        @if ($this->activeView !== 'all' && ! $this->tabHasRooms)
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('lobby.shelf.empty') }}</p>
        @endif

        {{-- An honestly empty store, when there is nothing at all. --}}
        @if ($this->shelves === [] && $this->evergreens->isEmpty())
            <flux:callout icon="user-group">
                <flux:callout.heading>No open rooms right now</flux:callout.heading>
                <flux:callout.text>{{ Voice::line('lobby.publics.empty') }}</flux:callout.text>
            </flux:callout>
        @endif

        {{-- The always-open tables, after the Saturday. All-tab only: an
             evergreen has no Saturday, so it belongs to no shelf, and
             folding it into one would be a label the data does not
             support. --}}
        @if ($this->activeView === 'all' && $this->evergreens->isNotEmpty())
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

        {{-- BRING SOMEBODY. The lobby is the walk-on destination and the
             only surface with a link that never goes stale: a room code
             would rot weekly and a private group's code cannot field a
             thin Saturday, so the invite worth sending is the codeless
             one. No code fallback here for the same reason — there is no
             code to read aloud across a room. --}}
        <div
            class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
            x-data="{
                copied: false,
                canShare: typeof navigator.share === 'function',
                copyLink() {
                    window.cfbClipboard.copy(@js($this->inviteUrl)).then((ok) => {
                        if (! ok) return;

                        this.copied = true;
                        setTimeout(() => this.copied = false, 2000);
                    });
                },
                share() {
                    navigator.share({
                        title: @js(Brand::name()),
                        text: @js(Voice::line('join.app.share_text', ['inviter' => auth()->user()->first_name])),
                        url: @js($this->inviteUrl),
                    }).catch(() => {});
                },
            }"
        >
            <flux:heading size="lg">Invite a friend</flux:heading>
            <flux:subheading>{{ Voice::line('join.app.hint') }}</flux:subheading>

            <div class="flex flex-wrap items-center gap-2">
                <span class="min-w-0 max-w-full truncate font-mono text-sm font-semibold">{{ Str::after($this->inviteUrl, '://') }}</span>
                <flux:button x-on:click="copyLink()" size="sm" variant="primary">
                    <span x-show="! copied">Copy link</span>
                    <span x-show="copied" x-cloak>Copied</span>
                </flux:button>
                <flux:button x-show="canShare" x-cloak x-on:click="share()" size="sm">
                    <flux:icon.box-arrow-up variant="micro" />
                    Share
                </flux:button>
            </div>
        </div>

        {{-- The other product, one line, and named as what it IS rather
             than as a mood: a reader standing in a store of one-Saturday
             rooms has to be told the season-long thing exists before
             "rather run your own?" means anything to them. --}}
        <x-link-row :href="route('pickem.create')" title="Want a season-long group?">
            <span class="block pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Private and invite-only — you run it, and the standings run all season.</span>
            <span class="text-micro block pt-0.5 text-zinc-500 dark:text-zinc-400">Name it, pick its mode, send one link.</span>
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
