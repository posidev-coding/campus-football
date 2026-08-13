<?php

use App\Actions\GrantWalletEntry;
use App\Enums\ContentRating;
use App\Livewire\Concerns\PicksTeams;
use App\Models\User;
use App\Support\Voice;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Guided setup, in place.
 *
 * A guest answers three small questions — name, grief tolerance, credentials
 * — and lands, logged in, on the favorite-team moment a signed-in user sees
 * immediately. It is an overlay rather than a route for the reason the search
 * panel is: never navigate away from the page the user is trying to fill in.
 *
 * Why three screens instead of the one form at /register — which still exists
 * for bookmarks — is conversion, not decoration: people finish a sequence of
 * single questions that they abandon as a wall of fields. Credentials come
 * LAST, once they are invested, which also means an abandoned signup has no
 * password or email to leave lying around. The handle is not asked at all:
 * nothing consumes it until Pick'em and chat exist, and claiming it lives on
 * Account (and later, at the first moment that actually needs one).
 */
new class extends Component
{
    use PicksTeams;

    /**
     * The three COUNTED steps — "easy as 1-2-3" is the product promise, and
     * the progress bar renders exactly this list. `team` is deliberately not
     * in it: the favorite-team moment sits past registration, styled as an
     * arrival rather than advertised as a fourth chore. It remains a valid
     * `$step` value; `next()` cannot reach it — `register()` is that door.
     */
    public const STEPS = ['name', 'rating', 'credentials'];

    public string $step = 'name';

    public string $first_name = '';

    public string $last_name = '';

    public string $content_rating = 'pg13';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        // Signed in, there is nothing to sign up for — the whole flow IS the
        // picker. A guest carrying a stale hand-off flag still has to make
        // an account first, so auth decides which pane an open overlay shows.
        $this->step = auth()->check() ? 'team' : 'name';
    }

    /**
     * True only on the registration hand-off: register() flashes the flag
     * and redirects, so the landing render — and ONLY that render — opens
     * straight onto the moment; the very next request has already consumed
     * it. This used to be `?start=team`, which was a landmine: a home-screen
     * install captures the tab's URL, so the query rode into the web clip
     * and "Who's your team?" reopened on every launch of the installed app
     * (and every pull-to-refresh). A flash cannot be bookmarked, and the
     * URL stays the clean `/` the manifest's start_url promises.
     */
    public function opensToMoment(): bool
    {
        return auth()->check() && session()->has('onboarding.moment');
    }

    /**
     * Everything the picker trait needs; also what the confirmation list on
     * the last screen renders, and what the splash personalizes its warmup
     * phrases from — `name` (the mascot column) and `location`, with
     * `short_display_name` for placeName()'s long-name fallback.
     */
    #[Computed]
    public function followedTeams()
    {
        return auth()->user()?->followedTeams()->get([
            'teams.id', 'slug', 'name', 'location', 'display_name', 'short_display_name', 'logo', 'logo_dark',
        ]) ?? collect();
    }

    /**
     * Validate ONLY the current screen, then advance.
     *
     * The point of splitting the form: a problem with an answer belongs on
     * the screen that asked the question, not in a pile at the end with
     * everything else already typed.
     */
    public function next(): void
    {
        /*
         * Credentials is the registration boundary: the only way past it is
         * register(). The guard also refuses 'team' — a state a caller could
         * post directly — so a validated GUEST can never walk into the picker
         * with no account to attach teams to.
         */
        if ($this->step === 'credentials' || $this->step === 'team') {
            return;
        }

        $this->validate($this->rulesFor($this->step));

        $index = array_search($this->step, self::STEPS, true);

        $this->step = self::STEPS[$index + 1] ?? 'credentials';
    }

    public function back(): void
    {
        /*
         * No Back from the picker: it sits PAST account creation, and backing
         * into a credentials form for an account that already exists helps
         * nobody. The header X is the way out.
         */
        if ($this->step === 'team') {
            return;
        }

        $index = array_search($this->step, self::STEPS, true);

        if ($index > 0) {
            $this->step = self::STEPS[$index - 1];
        }
    }

    /**
     * Create the account, then hand off to the picker.
     *
     * Everything is validated again together — the per-screen passes are for
     * feedback, not trust; a client can post straight here.
     */
    public function register(): void
    {
        $validated = $this->validate(
            collect(self::STEPS)->flatMap(fn (string $step) => $this->rulesFor($step))->all(),
        );

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered($user = User::create($validated)));

        Auth::login($user);
        session()->regenerate();

        /*
         * A FULL redirect, not `navigate: true`. Registering flips the whole
         * page's auth state — the bottom tab bar, the header, Home's own
         * branches — and a hard load re-renders all of it rather than hoping
         * a morph catches every `@auth` region.
         *
         * The hand-off rides a session FLASH, never the URL: the landing
         * load consumes it, so nothing about "open the picker" can be
         * bookmarked, refreshed, or captured into an installed app's URL.
         */
        session()->flash('onboarding.moment', true);

        $this->redirect(route('home'));
    }

    /** Finish: stop the prompt coming back, close, and hand off to the tour. */
    public function done(): void
    {
        $this->markOnboarded();

        $this->dispatch('close-onboarding');

        /*
         * The wizard's last screen is the tour's first. Two hand-offs,
         * because the two exits differ: after adding a team the tour is
         * already mounted (team-followed re-rendered Home) and held back by
         * the visible overlay, so `start-tour` is what starts it. After a
         * SKIP the tour was never mounted — the page predates onboarded_at —
         * so `start-tour` lands on nothing and `onboarding-finished` does
         * the work instead: Home re-renders, showTour now passes, the tour
         * mounts, and its own autoStart() begins over the placeholder card.
         */
        $this->dispatch('start-tour');
        $this->dispatch('onboarding-finished');
    }

    /**
     * The first pick COMPLETES the moment — no "add another", no Done button.
     * The tour teaches the five slots; this screen only asks the one question
     * worth an overlay: who's yours?
     */
    protected function afterTeamAdded(\App\Models\Team $team): void
    {
        /*
         * The seed reward: 25 XP for arriving with a team instead of skipping.
         * The ONE exception to "no earning before a verified email" — it
         * plants a number in the wallet worth protecting, and the idempotency
         * key makes it once-ever even if they unfollow everything and pass
         * through this moment again.
         */
        app(GrantWalletEntry::class)->handle(
            auth()->user(),
            xp: GrantWalletEntry::FIRST_TEAM_XP,
            lattes: 0,
            reason: GrantWalletEntry::REASON_FIRST_TEAM,
            key: GrantWalletEntry::REASON_FIRST_TEAM,
        );

        // Following a team IS completing onboarding — the CTA must not return
        // on the next visit to a page that now has their team on it.
        $this->markOnboarded();

        unset($this->followedTeams);

        /*
         * Three dispatches, in order. `team-followed` re-renders Home (the
         * glance cards, and the tour mounting behind the overlay).
         * `signup-splash` starts the branded beat — Livewire applies this
         * round trip's morph BEFORE browser events fire, so the splash's
         * phrases are already re-personalized to the pick when begin() runs.
         * `close-onboarding` closes the wizard underneath the splash and
         * clears the device draft via the same window handler the X uses.
         */
        $this->dispatch('team-followed');
        $this->dispatch('signup-splash');
        $this->dispatch('close-onboarding');
    }

    private function markOnboarded(): void
    {
        $user = auth()->user();

        if ($user !== null && ! $user->hasOnboarded()) {
            $user->forceFill(['onboarded_at' => now()])->save();
        }
    }

    /**
     * The same rules `auth/register` uses, split by screen. No handle rules
     * anywhere: registration no longer asks, and the column is nullable.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rulesFor(string $step): array
    {
        return match ($step) {
            'name' => [
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
            ],
            'rating' => ['content_rating' => ['required', Rule::enum(ContentRating::class)]],
            'credentials' => [
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
                'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
            ],
            default => [],
        };
    }

    #[Computed]
    public function stepNumber(): int
    {
        return (int) array_search($this->step, self::STEPS, true) + 1;
    }
}; ?>

{{-- `contents` so this root takes NO space in Home's flex column. Without it
     the component is an empty flex ITEM, and the parent's `gap-6` opens a
     24px hole above the first real card — which reads as an unexplained top
     margin. The overlay inside is `fixed`, so it does not need a box here. --}}
<div
    class="contents"
    x-data="{
        open: @js($this->opensToMoment()),

        /*
         * The in-progress answers survive a closed overlay, so an abandoned
         * signup can be picked up later.
         *
         * ONLY the first three screens are stored. The password and email live
         * on the last screen, so credentials are excluded BY CONSTRUCTION
         * rather than by a denylist someone widens later without noticing what
         * it was protecting. Do not add them here.
         */
        key: 'cfb.signup',

        /*
         * The ONLY fields that may ever be written. `password`, its
         * confirmation and `email` are absent, and the credentials screen
         * carries no save handler at all — two independent protections, so
         * neither a widened allowlist nor a stray handler leaks a credential
         * on its own.
         */
        fields: ['first_name', 'last_name', 'content_rating'],

        restore() {
            if (@js(auth()->check())) return

            let draft = {}
            try { draft = JSON.parse(localStorage.getItem(this.key) || '{}') } catch (e) { return }

            for (const field of this.fields) {
                if (draft[field]) $wire.set(field, draft[field], false)
            }
        },

        /*
         * Read from the ELEMENT that fired, never from `$wire`. The model
         * bindings here are deferred, so `$wire.handle` is still empty while
         * the user is typing into it — saving from `$wire` wrote a whole step
         * behind and, worse, could attribute one field's text to another
         * across a morph.
         */
        save(e) {
            if (@js(auth()->check())) return

            const field = e.target.getAttribute('wire:model')

            if (! this.fields.includes(field)) return

            let draft = {}
            try { draft = JSON.parse(localStorage.getItem(this.key) || '{}') } catch (err) {}

            draft[field] = e.target.value
            localStorage.setItem(this.key, JSON.stringify(draft))
        },

        clear() { localStorage.removeItem(this.key) },
    }"
    {{-- An authenticated visitor has no signup in progress, so any draft on
         this device is finished business — including the one the just-created
         account left behind, since `register()` redirects and the page unloads
         before anything client-side could tidy up. Clearing on the next load
         is what stops a stranger's name sitting in a shared browser. --}}
    x-init="@js(auth()->check()) ? clear() : restore()"
    @start-onboarding.window="open = true"
    @close-onboarding.window="open = false; clear()"
    @keydown.escape.window="open = false"
>
    {{-- `data-onboarding-overlay` names the wizard; `data-tour-holdoff` is
         what the tour actually waits on (the splash below carries it too).
         Checked with getClientRects(), never offsetParent: this element is
         `fixed`, and a fixed element's offsetParent is null even while it
         fills the screen.

         `x-cloak` is CONDITIONAL, and that condition kills a real glitch:
         on the post-registration redirect Alpine boots with `open` already
         true, so cloaking until boot painted the HOME SCREEN for a beat
         between steps four and five. On the hand-off load (the session
         flash, via opensToMoment()) the server renders the overlay visible
         from the first frame; everywhere else the cloak still prevents the
         opposite flash. Livewire update requests re-render the cloak (the
         flash died with the landing load), which is the same post-boot
         no-op it has always been.

         The fade matters on the way OUT: closing reveals Home underneath,
         and the tour waits a beat before claiming it — the reader should
         plainly SEE they are back on their home screen between the two. --}}
    <div
        @if (! $this->opensToMoment()) x-cloak @endif
        x-show="open"
        x-transition.opacity.duration.200ms
        data-onboarding-overlay
        data-tour-holdoff
        class="fixed inset-0 z-50 flex flex-col bg-white pt-[env(safe-area-inset-top)] dark:bg-zinc-950"
    >
        {{-- Closable at every step, per the brief: nobody is trapped in a
             signup they changed their mind about. --}}
        <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-4 py-2 dark:border-zinc-800">
            {{-- The mark holds this slot on EVERY step, with Back beside it
                 where there is one. Putting the brand in the slot only when
                 Back is absent made it appear and disappear as the reader
                 moved through signup, which reads as a rendering fault rather
                 than as chrome. Measured at 390px: mark 20 + Back ~72 + the
                 step counter ~60 + close ~32 sits well inside the row. --}}
            <div class="flex items-center gap-1">
                <x-brand.mark class="size-5 shrink-0" />

                @if ($step !== 'team' && $step !== 'name')
                    <flux:button wire:click="back" size="sm" variant="ghost" icon="chevron-left">Back</flux:button>
                @endif
            </div>

            {{-- Guests see the three-segment bar fill as they answer; the
                 team moment shows NOTHING — it sits past registration and is
                 an arrival, not a fourth chore. A signed-in user never sees a
                 counted step at all. --}}
            @if ($step !== 'team')
                @guest
                    <x-signup-progress :step="$this->stepNumber" :total="count(self::STEPS)" />
                @endguest
            @endif

            <flux:button @click="open = false" size="sm" variant="ghost" icon="x-mark" aria-label="Close" />
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-6 pb-[calc(env(safe-area-inset-bottom)+1.5rem)]">
            <div class="mx-auto flex w-full max-w-md flex-col gap-5">
                @if ($step === 'name')
                    <flux:heading size="xl">{{ Voice::line('onboarding.name') }}</flux:heading>

                    {{-- `wire:key` per step is load-bearing, not tidying.
                         Without it Livewire MORPHS step one's input into step
                         two's — same tag, same position — and the reused node
                         carried its old binding long enough for a keystroke to
                         land on the previous field.

                         The Continue button rides INSIDE the step column, tight
                         under the fields, so the thumb never has to leave the
                         form — and on a phone it stays above the keyboard
                         instead of hiding behind it, which is where a
                         bottom-pinned rail put it. --}}
                    <div class="flex flex-col gap-3" @input="save($event)" wire:key="step-name">
                        <flux:input wire:model="first_name" label="First name" autocomplete="given-name" />
                        <flux:input wire:model="last_name" label="Last name" autocomplete="family-name" />

                        <flux:button wire:click="next" variant="primary" class="mt-2 w-full">Continue</flux:button>
                    </div>
                @elseif ($step === 'rating')
                    <flux:heading size="xl">{{ Voice::line('onboarding.rating') }}</flux:heading>

                    <div class="flex flex-col gap-5" @change="save($event)" wire:key="step-rating">
                        <flux:radio.group
                            wire:model="content_rating"
                            description="We roast your picks, your team and your record — never you."
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

                        <flux:button wire:click="next" variant="primary" class="w-full">Continue</flux:button>
                    </div>
                @elseif ($step === 'credentials')
                    <flux:heading size="xl">{{ Voice::line('onboarding.credentials') }}</flux:heading>

                    {{-- No save handler here, and that is the point:
                         nothing on this screen is ever written to the device. --}}
                    <div class="flex flex-col gap-3" wire:key="step-credentials">
                        <flux:input wire:model="email" type="email" label="Email" autocomplete="email" />
                        <flux:input wire:model="password" type="password" label="Password" autocomplete="new-password" viewable />
                        <flux:input wire:model="password_confirmation" type="password" label="Confirm password" autocomplete="new-password" viewable />

                        <flux:button wire:click="register" variant="primary" class="mt-2 w-full">Create account</flux:button>
                    </div>
                @else
                    {{-- The favorite-team MOMENT — past registration, styled
                         as an arrival rather than a form step: centered mark
                         and heading, one question, and the first pick closes
                         the overlay itself (afterTeamAdded). Signed-in users
                         start here. `wire:key` per step is the same morph
                         insurance the counted steps carry. --}}
                    <div class="flex flex-col gap-5" wire:key="step-team">
                        <div class="flex flex-col items-center gap-3 pt-4 text-center">
                            <x-brand.mark class="size-10 shrink-0" />

                            <flux:heading size="xl">{{ Voice::line('onboarding.favorite') }}</flux:heading>

                            <flux:subheading class="max-w-xs">{{ Voice::line('onboarding.picker') }}</flux:subheading>
                        </div>

                        @if ($this->canFollowMore)
                            <div class="flex flex-col gap-2">
                                <flux:input
                                    wire:model.live.debounce.250ms="teamQuery"
                                    icon="magnifying-glass"
                                    placeholder="Search FBS teams…"
                                />

                                @if ($followError)
                                    <p class="text-micro text-amber-600 dark:text-amber-500">{{ $followError }}</p>
                                @endif

                                @forelse ($this->teamMatches as $match)
                                    <x-team-pick-row
                                        :team="$match"
                                        wire:click="addTeam({{ $match['id'] }})"
                                        wire:key="pick-{{ $match['id'] }}"
                                    />
                                @empty
                                    @if (trim($teamQuery) !== '')
                                        <p class="px-1 text-micro text-zinc-500">
                                            {{ Voice::line('teams.no_matches', ['query' => trim($teamQuery)]) }}
                                        </p>
                                    @endif
                                @endforelse
                            </div>
                        @else
                            {{-- Reachable only if something reopens the
                                 overlay for a full roster (a replay event, a
                                 stale hand-off). A friendly line beats a
                                 screen that is all heading and no input. --}}
                            <p class="text-center text-sm text-zinc-500">
                                {{ Voice::line('teams.at_limit', ['max' => \App\Models\User::MAX_FOLLOWED_TEAMS]) }}
                            </p>
                        @endif

                        {{-- No primary here — the PICKER is the action, and
                             the first pick completes the moment on its own.
                             Skipping stays quiet, and it fires the same splash:
                             the phrases personalize to Bandwagon State, so the
                             skip gets the joke instead of a lesser exit. --}}
                        <button
                            type="button"
                            wire:click="done"
                            @click="clear(); $dispatch('signup-splash')"
                            class="mx-auto block px-3 py-1.5 text-micro font-medium text-zinc-500 transition-colors hover:text-zinc-700 dark:hover:text-zinc-300"
                        >
                            Skip for now
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Authed only: the splash fires from done(), which only the team step
         reaches, and a guest finishes signup through a full redirect — their
         copy of this markup could never play. Rendering it anyway put
         Bandwagon State phrases in every guest page's hidden DOM. --}}
    @auth
    @php
        /*
         * Who the splash warms up for: the favorite (position 1), or
         * Bandwagon State when they skipped — which turns the same phrases
         * into their own punchline. Re-rendered on every team change, so the
         * spans below always name the current favorite; Alpine only tracks
         * which one is showing.
         *
         * The road-trip line prefers the favorite's actual stadium —
         * inferred from their home games by TeamVenue, one cached indexed
         * query — and falls back to the school when the games table has
         * nothing to say. The bandwagon's stadium is wherever the winning is.
         */
        $favorite = $this->followedTeams->first();
        $warmupFor = $favorite ?? App\Support\PlaceholderTeam::team();
        $warmupPlace = $favorite
            ? (App\Support\TeamVenue::nameFor($favorite->id) ?? $favorite->placeName())
            : App\Support\PlaceholderTeam::VENUE;
        /*
         * Ordered as a road trip: travel there, paint the field, learn the
         * song, THEN meet the fans — the high-five lands better once you have
         * arrived — and the Beast Lattes close, holding the screen longest,
         * because the last thing read is the thing remembered.
         */
        $warmupPhrases = [
            Voice::line('splash.warmup.travel', ['place' => $warmupPlace]),
            Voice::line('splash.warmup.field'),
            Voice::line('splash.warmup.song'),
            Voice::line('splash.warmup.greet', ['team' => $warmupFor->mascotName() ?: $warmupFor->placeName()]),
            Voice::line('splash.warmup.latte'),
        ];
    @endphp

    {{--
        The signup splash: the branded beat between Done and Home.

        Done could drop the reader straight onto their page — it is ready —
        but instantly is indistinguishable from abruptly: wizard vanishes,
        home appears, tour claims it, and none of it reads as an arrival. So
        this holds the screen for ~4 seconds wearing a fake to-do list, fades
        out to a plainly visible Home, and only then (via start-tour → the
        tour's own startSoon beat) do the coach marks land.

        Its own x-data island: the wizard's Alpine scope owns signup state,
        and the splash outlives the wizard's close. `data-tour-holdoff` keeps
        the tour waiting while it plays. Rendered AFTER the wizard div, so at
        the same z-50 it paints on top and the wizard's close happens out of
        sight beneath it.
    --}}
    {{-- `contents`, same as the component root and for the same reason: a
         plain div here is a ZERO-HEIGHT flex item in Home's gap-6 column,
         and gap applies on both sides of it — 48px of air between the
         search bar and the team cards instead of 24. The only child is
         `fixed`, which needs no box here. --}}
    <div
        class="contents"
        x-data="{
            show: false,
            i: 0,
            timer: null,

            begin() {
                if (this.show) return

                this.show = true
                this.i = 0

                /*
                 * 2400ms a phrase — read it, smile, breathe once — walking to
                 * the last phrase at 9600ms, which then hangs an extra beat
                 * before the fade: the Beast Lattes are the closer, and a
                 * closer does not rush off stage. Slow on purpose: this
                 * screen is the app introducing its whole personality, and
                 * it is allowed the seconds that takes.
                 */
                this.timer = setInterval(() => { this.i = Math.min(this.i + 1, 4) }, 2400)

                setTimeout(() => this.end(), 12500)
            },

            end() {
                clearInterval(this.timer)
                this.show = false
                this.$dispatch('start-tour')
            },
        }"
        x-on:signup-splash.window="begin()"
    >
        {{-- Forced DARK, whatever the theme: the `dark` class flips every
             `dark:` variant inside (the app's variant matches `.dark *`), so
             the mark's dark cut, the phrase text and the dots all pick their
             night versions with no per-element edits. The splash is a curtain
             moment, and curtains are black. --}}
        <div
            x-cloak
            x-show="show"
            x-transition:enter.opacity.duration.300ms
            x-transition:leave.opacity.duration.500ms
            data-signup-splash
            data-tour-holdoff
            class="dark fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-zinc-950 pt-[env(safe-area-inset-top)]"
        >
            <x-brand.mark class="size-16 shrink-0" />

            {{-- One line's worth of height, phrases crossfading through it —
                 absolute so a longer phrase never shifts the mark above. --}}
            <div class="relative h-6 w-full">
                @foreach ($warmupPhrases as $i => $phrase)
                    <span
                        x-cloak
                        x-show="i === {{ $i }}"
                        x-transition:enter.opacity.duration.400ms
                        x-transition:leave.opacity.duration.200ms
                        class="absolute inset-x-4 text-center text-sm font-medium text-zinc-500 dark:text-zinc-400"
                        wire:key="warmup-{{ $i }}"
                    >{{ $phrase }}</span>
                @endforeach
            </div>

            <div class="flex items-center gap-1.5" aria-hidden="true">
                <span class="size-1.5 animate-pulse rounded-full bg-zinc-300 dark:bg-zinc-700"></span>
                <span class="size-1.5 animate-pulse rounded-full bg-zinc-300 [animation-delay:200ms] dark:bg-zinc-700"></span>
                <span class="size-1.5 animate-pulse rounded-full bg-zinc-300 [animation-delay:400ms] dark:bg-zinc-700"></span>
            </div>
        </div>
    </div>
    @endauth
</div>
