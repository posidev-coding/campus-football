<?php

use App\Actions\FollowTeam;
use App\Actions\ReorderFollowedTeams;
use App\Actions\UnfollowTeam;
use App\Enums\ContentRating;
use App\Exceptions\FollowLimitReached;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Services\CfbCalendar;
use App\Support\Voice;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Account settings, and the teams a user cares about.
 *
 * Following a team dispatches that team's news fetch — see FollowTeam — so
 * this screen is what populates a user's home page and the team's News tab.
 *
 * The ORDER of the list is the model: it drives the Home swipe order, the
 * scoreboard float order, and whose news leads. There is no separate favorite
 * anymore; position 1 is what that meant.
 */
new class extends Component
{
    public string $first_name = '';

    public string $last_name = '';

    public string $handle = '';

    public string $content_rating = '';

    /**
     * The follow search query.
     *
     * A plain text input rather than a searchable listbox. Flux's listbox does
     * focus its inner search box on open, but that focus is PROGRAMMATIC, which
     * on touch does not raise the keyboard — so a phone user taps the control,
     * gets a popover, and has to tap again to type. Tapping a real input raises
     * the keyboard because the user touched it.
     */
    public string $teamSearch = '';

    /** Set when adding a team would exceed the follow limit. */
    public string $followError = '';

    public function mount(): void
    {
        $this->fillProfileForm();
    }

    /**
     * Reset the form to what is stored.
     *
     * Called on open as well as on mount, so abandoning an edit and reopening
     * does not present the half-typed values from last time as though they had
     * been saved.
     */
    public function fillProfileForm(): void
    {
        $user = auth()->user();

        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        $this->handle = $user->handle;
        $this->content_rating = $user->content_rating->value;
    }

    public function saveProfile(): void
    {
        $user = auth()->user();

        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            /*
             * `ignore($user)` so saving without touching the handle is not a
             * collision with yourself. The rule sits on a unique index over a
             * case-insensitive collation, so the database is the real guarantee
             * — this is the readable error, not the enforcement.
             */
            'handle' => [
                'required', 'string', 'min:3', 'max:20',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('users')->ignore($user->id),
            ],
            'content_rating' => ['required', Rule::enum(ContentRating::class)],
        ], [
            'handle.regex' => 'Handles use lowercase letters, numbers and underscores.',
        ]);

        $user->update($validated);

        Flux::modal('edit-profile')->close();
    }

    /**
     * Typing a capital should not become a validation error to read and fix.
     */
    public function updatedHandle(): void
    {
        $this->handle = Str::of($this->handle)->lower()->replaceMatches('/[^a-z0-9_]/', '')->substr(0, 20)->toString();
    }

    /**
     * The drag path. `wire:sort` reports ONE item and its new index, not the
     * whole list, and the index is 0-based.
     */
    public function reorder(int $teamId, int $position, ReorderFollowedTeams $action): void
    {
        $action->place(auth()->user(), $teamId, $position);

        unset($this->followed);
    }

    /**
     * The keyboard path to the same outcome. A drag handle is unreachable
     * without a pointer, and the order is the only way to say which team
     * leads — so it cannot be pointer-only.
     */
    public function move(int $teamId, int $offset, ReorderFollowedTeams $action): void
    {
        $action->move(auth()->user(), $teamId, $offset);

        unset($this->followed);
    }

    public function follow(int $teamId, FollowTeam $action): void
    {
        $team = Team::find($teamId);

        $this->followError = '';

        if ($team === null) {
            return;
        }

        try {
            $action->handle(auth()->user(), $team);
        } catch (FollowLimitReached $e) {
            $this->followError = Voice::line('follow.limit', ['max' => $e->limit]);

            return;
        }

        // Cleared on success only. A failed follow leaves the query in place so
        // the user can see what they were reaching for alongside the reason it
        // did not happen.
        $this->teamSearch = '';

        unset($this->followed, $this->followable, $this->matches);
    }

    public function unfollow(int $teamId, UnfollowTeam $action): void
    {
        $team = Team::find($teamId);

        if ($team !== null) {
            $action->handle(auth()->user(), $team);
        }

        // The freed slot has to show up in both the list and the picker, and
        // clearing the error matters too: unfollowing is exactly how a user
        // acts on "unfollow one to make room".
        $this->followError = '';

        unset($this->followed, $this->followable);
    }

    /**
     * FBS teams for the current season, so the picker is not 854 entries long.
     *
     * @return list<array{id:int, name:string}>
     */
    #[Computed]
    public function teams(): array
    {
        // Shared with Home's quick add, so the two pickers cannot drift and
        // only one of them pays for the query.
        return \App\Support\TeamGlance::fbsTeams();
    }

    /**
     * Teams available to follow — everything they are not already following.
     *
     * Offering a team already in the list below is noise, and picking it would
     * be a no-op that looks like a broken control.
     *
     * @return list<array{id:int, name:string}>
     */
    #[Computed]
    public function followable(): array
    {
        $already = $this->followed->pluck('id')->all();

        return collect($this->teams)
            ->reject(fn (array $team) => in_array($team['id'], $already, true))
            ->values()
            ->all();
    }

    /**
     * Teams matching the query, capped so the card cannot grow without bound.
     *
     * Empty for an empty query: showing all 136 FBS teams under the input would
     * bury everything below it on a phone.
     *
     * @return list<array{id:int, name:string}>
     */
    #[Computed]
    public function matches(): array
    {
        $query = trim($this->teamSearch);

        if ($query === '') {
            return [];
        }

        return collect($this->followable)
            ->filter(fn (array $team) => str_contains(
                mb_strtolower($team['name']), mb_strtolower($query)
            ))
            ->take(6)
            ->values()
            ->all();
    }

    #[Computed]
    public function atLimit(): bool
    {
        return $this->followed->count() >= User::MAX_FOLLOWED_TEAMS;
    }

    /**
     * Followed teams, pinned one first, then the rest alphabetically.
     *
     * Same order the scoreboard floats them in, so the list is a preview of
     * what pinning actually does rather than a differently-sorted copy.
     * PHP's sort is stable, so the alphabetical run survives underneath.
     */
    #[Computed]
    public function followed()
    {
        $pinned = auth()->user()->favorite_team_id;

        return auth()->user()
            ->followedTeams()
            ->orderBy('display_name')
            ->get(['teams.id', 'slug', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark'])
            ->sortBy(fn (Team $team) => $team->id === $pinned ? 0 : 1)
            ->values();
    }
}; ?>

<div class="flex flex-col gap-4">
    {{-- Sticky, because this screen only grows — the heading has to stay put
         once settings run past a viewport.

         Same offsets as the scoreboard's chrome, and for the same reasons:
         `-mt-5` cancels the layout container's `py-5` so the block rests exactly
         where it sticks instead of drifting up on the first scroll, and the `sm`
         offset is 14 spacing units PLUS ONE PIXEL because the header is `h-14`
         plus its own `border-b`. --}}
    <div class="sticky top-0 z-30 -mx-4 -mt-5 flex items-center justify-between gap-3 bg-white px-4 pt-3 pb-2 sm:top-[calc(var(--spacing)*14+1px)] dark:bg-zinc-950">
        <flux:heading size="xl">Account</flux:heading>

        {{-- Icon-only. The three labels were the widest thing in the card and
             said less than the icons do; up here they would not fit beside the
             heading at 390px at all.

             `$flux.appearance` is Flux's own store — it writes the `.dark` class
             on <html>, persists to localStorage, and keeps listening to the OS
             preference after load so "System" keeps tracking rather than
             freezing at whatever it was when the page rendered. --}}
        <flux:radio.group x-data variant="segmented" size="sm" x-model="$flux.appearance" class="shrink-0">
            <flux:radio value="light" icon="sun" aria-label="Light" />
            <flux:radio value="dark" icon="moon" aria-label="Dark" />
            <flux:radio value="system" icon="computer-desktop" aria-label="Match system" />
        </flux:radio.group>
    </div>

    <flux:card class="flex flex-col gap-3">
        <div class="flex items-center gap-3">
            <flux:avatar :initials="auth()->user()->initials()" />

            <div class="flex min-w-0 flex-col">
                <span class="truncate font-medium">{{ auth()->user()->name }}</span>
                {{-- The handle leads, the email follows: the handle is what
                     other people see, the email is only how you sign in. --}}
                <span class="truncate text-sm text-zinc-500">&#64;{{ auth()->user()->handle }}</span>
                <span class="truncate text-micro text-zinc-400">{{ auth()->user()->email }}</span>
            </div>

            {{-- Reset on open, not only on mount: abandoning an edit and coming
                 back should show what is stored, not last time's typing. --}}
            <flux:modal.trigger name="edit-profile">
                <flux:button
                    wire:click="fillProfileForm"
                    size="xs"
                    variant="ghost"
                    icon="pencil-square"
                    class="ms-auto shrink-0"
                    aria-label="Edit your profile"
                />
            </flux:modal.trigger>
        </div>

        <flux:separator />

        <div class="flex items-center justify-between gap-3 text-sm">
            <span class="shrink-0 text-zinc-500">Trash talk</span>
            <flux:badge size="sm" class="shrink-0">
                {{ auth()->user()->content_rating->label() }} · {{ auth()->user()->content_rating->subLabel() }}
            </flux:badge>
        </div>
    </flux:card>

    {{-- Email is absent on purpose. Changing it has to re-verify, which is its
         own flow with its own security consequences — quietly editing it beside
         a display name would be the wrong shape for that. --}}
    <flux:modal name="edit-profile" class="w-full max-w-md">
        <form wire:submit="saveProfile" class="flex flex-col gap-5">
            <div class="flex flex-col gap-1">
                <flux:heading size="lg">Edit profile</flux:heading>
                <flux:subheading>{{ Voice::line('profile.subheading') }}</flux:subheading>
            </div>

            <div class="flex gap-3">
                <flux:input wire:model="first_name" label="First name" class="flex-1" autocomplete="given-name" />
                <flux:input wire:model="last_name" label="Last name" class="flex-1" autocomplete="family-name" />
            </div>

            {{-- Masked on the CLIENT as well as sanitised on the server.
                 Livewire will not overwrite a focused input — that is what
                 keeps it from clobbering your typing — so a server-side clean
                 leaves the visible text disagreeing with the stored value until
                 blur. The mask corrects the character as it is typed; the
                 server rule stays as the guarantee. --}}
            <flux:input
                wire:model="handle"
                x-mask:dynamic="$input.toLowerCase().replace(/[^a-z0-9_]/g, '').slice(0, 20)"
                label="Handle"
                autocomplete="username"
                description="Lowercase letters, numbers and underscores."
            />

            <flux:radio.group
                wire:model="content_rating"
                label="Trash talk"
                :description="Voice::line('profile.rating_description')"
                variant="cards"
                class="flex-col"
            >
                @foreach (ContentRating::cases() as $rating)
                    <flux:radio
                        :value="$rating->value"
                        :label="$rating->label()"
                        :description="$rating->description()"
                    >
                        <span class="flex items-baseline gap-2">
                            <span class="font-medium">{{ $rating->label() }}</span>
                            <span class="text-sm text-zinc-500">{{ $rating->subLabel() }}</span>
                        </span>
                    </flux:radio>
                @endforeach
            </flux:radio.group>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- One card, not two. Pinning used to be its own picker over every FBS
         team, which meant it could select a team the user did not follow — so
         it had to be refused at the cap, and two searchable listboxes on one
         screen collided badly enough that picking a team to follow silently
         rewrote the pinned one. Pinning from the list the user already has
         cannot do either. --}}
    <flux:card class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg">Your teams</flux:heading>

                {{-- The count answers the question the cap raises — how many
                     slots are left — without spending a sentence on it. --}}
                <flux:badge size="sm" :color="$this->atLimit ? 'amber' : 'zinc'">
                    {{ $this->followed->count() }} / {{ App\Models\User::MAX_FOLLOWED_TEAMS }}
                </flux:badge>
            </div>

            <flux:subheading>{{ Voice::line('teams.subheading') }}</flux:subheading>
        </div>

        {{-- Adding a team belongs here, not only on a team page. Reaching the
             team page first means already knowing where to go; this is the
             screen someone opens when they want to change who they follow.

             Disabled rather than hidden at the cap, so the control does not
             vanish and leave the limit unexplained — and the placeholder says
             what happened and what to do about it. --}}
        <flux:input
            wire:model.live.debounce.200ms="teamSearch"
            size="sm"
            icon="magnifying-glass"
            :disabled="$this->atLimit"
            {{-- The prompt stays plain — it is an affordance, and a joke in a
                 placeholder is read every time the field is empty. The AT-LIMIT
                 message is the one talking to the user about something they
                 did, so that one speaks in their register. --}}
            :placeholder="$this->atLimit
                ? Voice::line('teams.at_limit', ['max' => App\Models\User::MAX_FOLLOWED_TEAMS])
                : 'Search teams to follow'"
            :clearable="! $this->atLimit"
        />

        @if (! $this->atLimit && trim($teamSearch) !== '')
            <div class="flex flex-col gap-1">
                @forelse ($this->matches as $match)
                    <button
                        type="button"
                        wire:click="follow({{ $match['id'] }})"
                        wire:key="match-{{ $match['id'] }}"
                        class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    >
                        <flux:icon name="plus" variant="micro" class="shrink-0 text-zinc-400" />
                        <span class="min-w-0 truncate">{{ $match['name'] }}</span>
                    </button>
                @empty
                    <p class="px-2 py-1.5 text-sm text-zinc-500">
                        {{ Voice::line('teams.no_matches', ['query' => $teamSearch]) }}
                    </p>
                @endforelse
            </div>
        @endif

        @if ($followError)
            <p class="text-micro text-amber-600 dark:text-amber-500">{{ $followError }}</p>
        @endif

    {{-- Drag to reorder. `wire:sort` is Livewire's own — it brings SortableJS
         and its 150ms shuffle, so the hand-rolled FLIP that used to animate a
         pin moving is gone with the pin.

         Its handler reports ONE item and its new index, not the whole list,
         and that index is 0-based (Sortable's `newIndex`). `place()` rebuilds
         the full order from it so the drag path gets the same membership
         validation as the keyboard path. --}}
    <div wire:sort="reorder($item, $position)" class="flex flex-col gap-3">
        @foreach ($this->followed as $team)
            <div
                wire:sort:item="{{ $team->id }}"
                wire:key="followed-{{ $team->id }}"
                class="flex items-center gap-2 rounded-lg bg-white dark:bg-zinc-900"
            >
                {{-- The handle is what makes the ROW draggable without
                     capturing taps on the links inside it. --}}
                <span
                    wire:sort:handle
                    class="shrink-0 cursor-grab touch-none p-1 text-zinc-300 active:cursor-grabbing dark:text-zinc-600"
                    aria-hidden="true"
                >
                    <flux:icon name="bars-3" variant="micro" />
                </span>

                <span class="tabular w-4 shrink-0 text-micro font-semibold text-zinc-400">{{ $loop->iteration }}</span>

                <x-team-link :team="$team" size="sm" class="min-w-0 flex-1" />

                {{-- The keyboard path to the same outcome. A drag handle is
                     unreachable without a pointer, and the order is the only
                     way to say which team leads — so it cannot be
                     pointer-only. Hidden from pointer users at `sm` and up
                     would be worse, not better: they are small and quiet. --}}
                <flux:button
                    wire:click="move({{ $team->id }}, -1)"
                    size="xs"
                    square
                    variant="ghost"
                    class="shrink-0"
                    :disabled="$loop->first"
                    aria-label="Move {{ $team->display_name }} up"
                    icon="chevron-up"
                />

                <flux:button
                    wire:click="move({{ $team->id }}, 1)"
                    size="xs"
                    square
                    variant="ghost"
                    class="shrink-0"
                    :disabled="$loop->last"
                    aria-label="Move {{ $team->display_name }} down"
                    icon="chevron-down"
                />

                <flux:button
                    wire:click="unfollow({{ $team->id }})"
                    size="xs"
                    variant="ghost"
                    icon="x-mark"
                    class="shrink-0"
                    aria-label="Unfollow {{ $team->display_name }}"
                />
            </div>
        @endforeach

        @if ($this->followed->isEmpty())
            <flux:text class="text-sm text-zinc-500">
                {{ Voice::line('teams.empty') }}
            </flux:text>
        @endif
    </div>
    </flux:card>

    {{--
        Admin and sign-out live here, not only in the desktop avatar menu.
        The header is hidden below `sm`, so anything reachable only from that
        dropdown would be unreachable on a phone — which is exactly the failure
        this navigation rework exists to remove.
    --}}
    <flux:card class="flex flex-col gap-2">
        @if (auth()->user()->isAdmin())
            <flux:button href="/admin" icon="wrench-screwdriver" size="sm" variant="ghost" class="justify-start">
                Admin
            </flux:button>
        @endif

        <flux:button
            type="submit"
            form="logout-form-account"
            icon="arrow-right-start-on-rectangle"
            size="sm"
            variant="ghost"
            class="justify-start"
        >
            Log out
        </flux:button>

        <form id="logout-form-account" method="POST" action="{{ route('logout') }}" class="hidden">
            @csrf
        </form>
    </flux:card>
</div>
