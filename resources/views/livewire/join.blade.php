<?php

use App\Actions\JoinGroup;
use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Exceptions\ContestFull;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\WalletTooLight;
use App\Models\Contest;
use App\Models\Group;
use App\Models\Slate;
use App\Models\User;
use App\Support\Voice;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * THE INVITE LANDING — what a shared /join link opens on, in two shapes.
 *
 * WITH A CODE it is the group's name, its game and its people BEFORE any
 * wall, because the moment someone taps a friend's link is the whole
 * acquisition funnel. Guests read everything; the join tap walks them
 * through auth and the intended-URL machinery lands them straight back
 * here, one tap from seated. A dead code gets words and a door, never a
 * 404 — and an already-seated member skips the pitch entirely, straight
 * to their clubhouse.
 *
 * WITHOUT ONE — `/join?by=handle` — it is the APP invite: the message an
 * inviter actually wants to send. A private group's code cannot carry it
 * (a group that cannot field a thin Saturday has nothing to sell) and a
 * room's code would go stale weekly, so a codeless link sells the product
 * and the door is the Lobby. There is nothing to preview and nothing to
 * be seated into, which is why the branches are three and not two: the
 * miss card tells you to ask your friend for a fresh link, and that is
 * exactly the wrong thing to say to somebody holding a working one.
 *
 * `?by=` credits the inviter in both shapes when the handle is real and
 * says nothing when it is not.
 *
 * The flag check lives HERE, not in middleware: the flag scopes to the
 * user, so a route wall would 400 every guest the product exists for. A
 * codeless invite therefore bounces to pickem.home while the flag is
 * closed — the link goes live with the surface it points at.
 */
new class extends Component
{
    public string $code = '';

    /** The inviter's handle, riding the link as ?by= — display only. */
    #[Url]
    public ?string $by = null;

    public function mount(string $code = ''): void
    {
        if (! Feature::active('pickem')) {
            $this->redirectRoute('pickem.home', navigate: true);

            return;
        }

        $this->code = strtoupper(trim($code));

        // The top of the acquisition funnel — counted BEFORE the
        // already-a-member redirect below, because a link that lands on
        // somebody who is already in still measures the link.
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened);

        // Hydrated from the querystring: keep it only when it LOOKS like
        // a handle; anything else is silently nothing, never an error.
        if ($this->by !== null && preg_match('/^[a-z0-9_]{3,20}$/', $this->by) !== 1) {
            $this->by = null;
        }

        if (auth()->check()
            && $this->group !== null
            && $this->group->memberships()->where('user_id', auth()->id())->exists()) {
            $this->redirectRoute(
                $this->group->isRoom() ? 'pickem.room' : 'pickem.group',
                $this->group,
                navigate: true,
            );
        }
    }

    /**
     * A link with no code behind it — the app invite. Read off the code
     * rather than off `group`, because "no group" is also what a DEAD
     * code resolves to and the two get opposite screens.
     */
    #[Computed]
    public function isAppInvite(): bool
    {
        return $this->code === '';
    }

    #[Computed]
    public function group(): ?Group
    {
        if ($this->code === '') {
            return null;
        }

        return Group::query()
            ->where('code', $this->code)
            ->withCount('memberships')
            // season_id rides along for the Week 0 / Week 1 label — the
            // split-week resolver reaches through week->season.
            ->with('week:id,number,season_id')
            ->first();
    }

    /** The room's card date, for the fans' Week 0 / Week 1 label. */
    #[Computed]
    public function roomSaturday(): ?string
    {
        $group = $this->group;

        if ($group === null || ! $group->isRoom()) {
            return null;
        }

        // value() hydrates through the date cast — re-pin to the plain
        // calendar date so the declared type holds by construction and no
        // accidental __toString ever carries a timezone.
        return Slate::query()
            ->whereHas('contest', fn ($q) => $q->where('group_id', $group->id))
            ->where('week_id', $group->week_id)
            ->value('saturday')?->format('Y-m-d');
    }

    /**
     * The group's contest — the mode identity the card wears, resolved
     * exactly the way the clubhouse resolves it.
     */
    #[Computed]
    public function contest(): ?Contest
    {
        if ($this->group === null) {
            return null;
        }

        return Contest::query()
            ->where('group_id', $this->group->id)
            ->orderByRaw("FIELD(mode, 'tiered', 'classic', 'woodshed')")
            ->first();
    }

    #[Computed]
    public function inviter(): ?User
    {
        return $this->by === null
            ? null
            : User::query()->where('handle', $this->by)->first(['id', 'handle']);
    }

    #[Computed]
    public function roomFull(): bool
    {
        $group = $this->group;

        return $group !== null
            && $group->isRoom()
            && $group->member_cap !== null
            && $group->memberships_count >= $group->member_cap;
    }

    /** Same derivation as JoinGroup's guard: every game already kicked. */
    #[Computed]
    public function roomPlayed(): bool
    {
        $group = $this->group;

        if ($group === null || ! $group->isRoom()) {
            return false;
        }

        $slate = Slate::query()
            ->whereHas('contest', fn ($q) => $q->where('group_id', $group->id))
            ->where('week_id', $group->week_id)
            ->with('games.game:id,kickoff_at,status,completed')
            ->first();

        return $slate !== null
            && $slate->games->isNotEmpty()
            && $slate->games->every(fn ($slateGame) => $slateGame->game->hasKickedOff());
    }

    public function join(JoinGroup $action)
    {
        if ($this->group === null) {
            return;
        }

        if (auth()->guest()) {
            // By hand, not redirect()->guest(): inside a Livewire action
            // the "current URL" is /livewire/update, and login would land
            // the new member on machinery instead of back here.
            session()->put('url.intended', route('pickem.join', array_filter([
                'code' => $this->code,
                'by' => $this->by,
            ]), absolute: false));

            // REGISTER, not login: the invite link is the PRIMARY
            // acquisition path, and the guest holding one is almost always
            // brand new — a login form is a door they cannot open. The
            // register screen links to sign-in for the few who can.
            return $this->redirectRoute('register', navigate: true);
        }

        try {
            $action->handle(auth()->user(), $this->group);
        } catch (PickemParticipationGated) {
            $this->addError('join', Voice::line('groups.verify_first'));

            return;
        } catch (ContestFull) {
            $this->addError('join', Voice::line('contest.room.full'));
            unset($this->group, $this->roomFull, $this->roomPlayed);

            return;
        } catch (WalletTooLight) {
            $this->addError('join', Voice::line('contest.room.too_light'));

            return;
        }

        session()->flash('status', Voice::line('groups.joined', ['group' => $this->group->name]));

        return $this->redirectRoute(
            $this->group->isRoom() ? 'pickem.room' : 'pickem.group',
            $this->group,
            navigate: true,
        );
    }

    /**
     * The app invite's one door. Mirrors join()'s guest arm — the
     * intended URL is put by hand for the same reason, and REGISTER for
     * the same reason — with one difference: there is nothing to be
     * seated into, so the destination is the LOBBY rather than a return
     * trip to this screen. Coming back here would only show the pitch a
     * second time.
     */
    public function start()
    {
        if (auth()->guest()) {
            session()->put('url.intended', route('pickem.lobby', absolute: false));

            return $this->redirectRoute('register', navigate: true);
        }

        return $this->redirectRoute('pickem.lobby', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-5 md:mx-auto md:w-full md:max-w-2xl">
    <h1 class="sr-only">{{ $this->isAppInvite ? "Join Pick'em" : 'Join a group' }}</h1>

    {{-- THREE branches, and the ORDER is the whole rule. A codeless link
         is a WORKING invite that resolves no group, which is also what a
         dead code does — so the app invite is asked FIRST, or the miss
         card tells somebody holding a good link to go get a fresh one. --}}
    @if ($this->isAppInvite)
        {{-- THE APP INVITE: no code, so nothing to preview and nothing to
             be seated into. The pitch is the product and the door is the
             Lobby, where the walk-on seats actually are. --}}
        @if ($this->inviter !== null)
            {{-- The personal note a link carries and a code never could. --}}
            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">
                &commat;{{ $this->inviter->handle }} invited you
            </p>
        @endif

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="flex flex-col gap-1">
                <flux:heading size="lg">{{ Voice::line('join.app.heading') }}</flux:heading>
                <flux:subheading>{{ Voice::line('join.app.body') }}</flux:subheading>
            </div>

            {{-- The two facts that tell the products apart, plain in every
                 register — the same pair the lobby leads with, because a
                 reader who has never seen the app cannot hear a joke about
                 a room until they know what a room is. --}}
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Public rooms are open to anyone and play a single Saturday.
                Private groups are invite-only and run all season.
            </p>

            <div class="flex flex-col gap-2">
                <flux:button wire:click="start" variant="primary" class="self-start">
                    @guest Create your account @else Go to the Lobby @endguest
                </flux:button>

                @guest
                    {{-- What the tap does, told straight. --}}
                    <p class="text-micro text-zinc-500">You'll create an account first — about a minute — and land in the Lobby, where you take a seat.</p>
                @endguest
            </div>
        </div>
    @elseif ($this->group === null)
        {{-- THE MISS: words and a door, never a 404 — a shared link that
             died deserves an exit that still sells the game. --}}
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 px-4 py-8 text-center dark:border-zinc-700">
            <flux:heading size="lg">Invite not found</flux:heading>
            <flux:subheading>{{ Voice::line('join.miss') }}</flux:subheading>
            <flux:button :href="route('pickem.lobby')" wire:navigate variant="primary">Go to the Lobby</flux:button>
        </div>
    @else
        @php
            $mode = $this->contest?->mode;
            $palette = $mode?->palette();
            // The card this room actually deals — Shotgun's size is frozen
            // per Saturday, so the pitch is sized from the CONTEST rather
            // than the mode's ten-game default.
            $modeGames = $this->contest === null
                ? null
                : $this->contest->mode->engine($this->contest->settings)->slateSize();
        @endphp

        @if ($this->inviter !== null)
            {{-- The personal note a link carries and a code never could. --}}
            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">
                &commat;{{ $this->inviter->handle }} invited you
            </p>
        @endif

        {{-- THE PREVIEW: what you were invited to, before any wall. --}}
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="flex items-start gap-3">
                @if ($mode !== null)
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-lg border {{ $palette['tile'] }}">
                        <flux:icon :name="$mode->icon()" variant="mini" class="{{ $palette['icon'] }}" />
                    </span>
                @endif

                <div class="min-w-0 flex-1">
                    <p class="truncate text-xl font-bold leading-tight">{{ $this->group->name }}</p>
                    {{-- The KIND leads. A link lands somebody who has
                         never seen the app on a name, a mode chip and a
                         member count — none of which say whether they
                         are being invited to somebody's season or to one
                         Saturday with strangers. Facts only; join.pitch
                         underneath carries the mood. --}}
                    <p class="pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                        @if ($this->group->isLobby())
                            Public room ·
                            @if ($this->group->isRoom() && $this->group->week !== null)
                                {{ \App\Support\Cadence::displayWeekLabel($this->group->week, $this->roomSaturday) }} ·
                            @endif
                            @if ($this->group->member_cap !== null)
                                {{ $this->group->memberships_count }} of {{ $this->group->member_cap }} seats
                            @else
                                {{ $this->group->memberships_count }} {{ Str::plural('member', $this->group->memberships_count) }}
                            @endif
                        @else
                            Private group, all season ·
                            {{ $this->group->memberships_count }} {{ Str::plural('member', $this->group->memberships_count) }}
                        @endif
                    </p>
                </div>

                @if ($mode !== null)
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-micro font-semibold {{ $palette['chip'] }}">{{ $mode->label() }}</span>
                @endif
            </div>

            @if ($mode !== null)
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->group->flavorEnum()?->blurb($modeGames) ?? $mode->blurb($modeGames) }}</p>
            @endif

            <flux:subheading>{{ Voice::line('join.pitch', ['group' => $this->group->name]) }}</flux:subheading>

            @auth
                <livewire:verify-callout :body-key="'verify.picks.body'" :dismissable="false" @email-verified="$refresh" />
            @endauth

            @error('join')
                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            @if ($this->roomFull)
                {{-- The state stays plain; the Voice line carries the mood. --}}
                <p class="text-sm font-medium">No open seats.</p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('contest.room.full') }}</p>
                <flux:button :href="route('pickem.lobby')" wire:navigate class="self-start">Find another room</flux:button>
            @elseif ($this->roomPlayed)
                <p class="text-sm font-medium">This week is already being played.</p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('join.room.played') }}</p>
                <flux:button :href="route('pickem.lobby')" wire:navigate class="self-start">Find another room</flux:button>
            @else
                <div class="flex flex-col gap-2">
                    <flux:button wire:click="join" variant="primary" class="self-start">Take your seat</flux:button>

                    @guest
                        {{-- What the tap does, told straight. --}}
                        <p class="text-micro text-zinc-500">You'll sign in or create an account first — then you land right back here, seated.</p>
                    @endguest
                </div>
            @endif
        </div>
    @endif
</div>
