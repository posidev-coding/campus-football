<?php

use App\Actions\FollowTeam;
use App\Actions\SetFavoriteTeam;
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
 * Choosing a favorite or following a team dispatches that team's news fetch —
 * see FollowTeam — so this screen is what populates a user's home page and the
 * team's News tab.
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

    /**
     * Pin a team the user already follows, or unpin it.
     *
     * "Pinned" is the user-facing word for what the schema calls
     * `favorite_team_id`: the one team whose news leads the home page and whose
     * games sit at the top of the scoreboard. The column keeps its name — it is
     * referenced across the app — but nothing says "favorite" to a reader.
     *
     * The same control both ways, so there is no separate "none" option to hunt
     * for. Because the team is always one they already follow, this can never
     * hit the follow cap.
     */
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

    public function togglePin(int $teamId, SetFavoriteTeam $action): void
    {
        $user = auth()->user();

        if (! $user->followedTeams()->whereKey($teamId)->exists()) {
            return;
        }

        $action->handle($user, $user->favorite_team_id === $teamId ? null : Team::find($teamId));

        unset($this->followed, $this->followable);
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
        $year = app(CfbCalendar::class)->scoreboardYear();

        return Cache::remember("account:teams:{$year}", 3600, fn () => Team::query()
            ->whereIn('id', TeamSeason::where('season_year', $year)
                ->where('classification', 'FBS')
                ->pluck('team_id'))
            ->orderBy('display_name')
            ->get(['id', 'display_name'])
            ->map(fn (Team $t) => ['id' => $t->id, 'name' => $t->display_name])
            ->all());
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

    {{-- Rows slide to their new positions when the pin moves.

         A FLIP: record every row's offset before the click goes out, and once
         Livewire has re-ordered the DOM, put each row back where it was with a
         transform and let a transition carry it to where it now belongs. CSS
         alone cannot do this — reordering is not an animatable property, so
         without the transform the list simply snaps.

         Positions are captured on the CAPTURE phase, which is what makes them
         "before": the click has not reached the button yet, so nothing has
         moved. --}}
    <div
        x-data="{
            before: {},
            capture() {
                this.before = {}
                this.$el.querySelectorAll('[data-row]').forEach((row) => {
                    this.before[row.dataset.row] = row.getBoundingClientRect().top
                })
            },
            play() {
                // Consumed, not just read. Livewire's morph fires the observer
                // more than once per update, and a second pass would measure a
                // row that is already mid-flight.
                const before = this.before
                this.before = {}

                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

                this.$el.querySelectorAll('[data-row]').forEach((row) => {
                    const was = before[row.dataset.row]

                    if (was === undefined) return

                    const delta = was - row.getBoundingClientRect().top

                    if (! delta) return

                    /*
                     * animate() rather than setting a transform and clearing it
                     * on the next frame. That two-step version got STUCK: the
                     * morph can replace a row between the two, so the cleanup
                     * ran against a detached node and the row froze at its full
                     * offset. This leaves no inline style behind at all, so
                     * there is nothing to strand.
                     */
                    row.animate(
                        [{ transform: `translateY(${delta}px)` }, { transform: 'translateY(0)' }],
                        { duration: 300, easing: 'cubic-bezier(0.2, 0, 0, 1)' },
                    )
                })
            },
        }"
        x-on:click.capture="capture()"
        x-init="new MutationObserver(() => play()).observe($el, { childList: true })"
        class="flex flex-col gap-3"
    >
        @forelse ($this->followed as $team)
            @php $pinned = auth()->user()->favorite_team_id === $team->id; @endphp

            <div class="flex items-center gap-2" data-row="{{ $team->id }}" wire:key="followed-{{ $team->id }}">
                {{-- The same control both ways: press to pin, press again to
                     unpin. Bootstrap ships outline and filled as two separate
                     icons, so the state swaps the component — a genuinely
                     filled pin, not the same glyph recoloured, which means no
                     separate badge repeating what the icon already says.

                     Passed as a CHILD, not through `icon="..."`. That prop
                     resolves against Flux's own icon set and silently falls
                     back when the name is not in it — the button rendered a
                     24px stroked glyph while `flux:icon.pin-angle` on its own
                     rendered the 16px Bootstrap one. As a child the component
                     is unambiguous, and its colour can be set directly rather
                     than fought past the button's own `text-*`.

                     Always `ghost`. The filled and coloured pin carries the
                     state on its own; a chip behind it added a second, weaker
                     signal saying the same thing.

                     `aria-pressed` is what makes it a toggle to a screen reader;
                     without it both states announce as the same button. --}}
                <flux:button
                    wire:click="togglePin({{ $team->id }})"
                    size="xs"
                    square
                    variant="ghost"
                    class="shrink-0"
                    :aria-pressed="$pinned ? 'true' : 'false'"
                    aria-label="{{ $pinned ? 'Unpin '.$team->display_name : 'Pin '.$team->display_name.' to your home page' }}"
                >
                    @if ($pinned)
                        <flux:icon.pin-angle-fill variant="micro" class="text-blue-500" />
                    @else
                        <flux:icon.pin-angle variant="micro" class="text-zinc-400" />
                    @endif
                </flux:button>

                <x-team-link :team="$team" size="sm" class="min-w-0 flex-1" />

                <flux:button
                    wire:click="unfollow({{ $team->id }})"
                    size="xs"
                    variant="ghost"
                    icon="x-mark"
                    class="shrink-0"
                    aria-label="Unfollow {{ $team->display_name }}"
                />
            </div>
        @empty
            <flux:text class="text-sm text-zinc-500">
                {{ Voice::line('teams.empty') }}
            </flux:text>
        @endforelse
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
