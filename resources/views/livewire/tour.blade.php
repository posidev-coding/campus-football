<?php

use App\Actions\GrantWalletEntry;
use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Support\Tours;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The guided coach-mark tour: a spotlight that walks a reader through the
 * things worth knowing on a screen.
 *
 * TWO WALKS, ONE COMPONENT. `home` is the app's first-run story, closing on
 * the install with the detected browser's actual steps inside the card;
 * `picks` is the economy's, added when Tallboys gained two sinks and a
 * cooler worth explaining. They differ in exactly three things — the step
 * list, the copy those keys resolve, and the column the finish stamps — so
 * a second component would have been a second copy of the spotlight
 * geometry, and the geometry is the part with the scars on it.
 *
 * The HOST decides whether this renders (Home: onboarded and never toured;
 * My Picks: never walked — or an explicit replay on either); this component
 * only runs the walk and stamps the finish. Everything positional is
 * client-side: targets are `[data-tour]` elements, and each step spotlights
 * whichever element wearing its key is actually VISIBLE — the bottom tab
 * below `sm`, the header chip above it — so one step list serves every
 * width for free.
 *
 * No requestAnimationFrame anywhere: positions are computed on step change
 * and window resize, scrolling uses `behavior: 'instant'`, and the movement
 * between spotlights is a CSS transition — so the end state is real even in
 * an automated tab that never produces a rendering frame.
 */
new class extends Component
{
    /** Which walk this is. The lists themselves live on App\Support\Tours. */
    public string $walk = Tours::HOME;

    /**
     * This walk's stops, in order — the ONE list the view reads twice.
     *
     * @return list<string>
     */
    #[Computed]
    public function steps(): array
    {
        return Tours::stepsFor($this->walk);
    }
    /**
     * Whether the first-team seed grant exists. The wallet stop mentions the
     * XP only when it was actually paid — a skipper tours too, and telling
     * them about money they never earned would be the app's first lie.
     */
    public bool $seeded = false;

    /**
     * The reader's first team's place name, for the search stop's example —
     * their own team, never a canned school (a hardcoded example is
     * somebody's rival). Null when they skipped the picker: no data, so the
     * example names nobody.
     */
    public ?string $searchTeam = null;

    public function mount(string $walk = Tours::HOME): void
    {
        $this->walk = Tours::known($walk) ? $walk : Tours::HOME;

        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $this->seeded = $user->walletEntries()
            ->where('key', GrantWalletEntry::REASON_FIRST_TEAM)
            ->exists();

        $this->searchTeam = $user->followedTeams()->first()?->placeName();
    }

    /**
     * Stamped on ARRIVING at the install stop as well as on finishing or
     * skipping. The install stop's happy path never taps another tour
     * control: the reader follows the share-sheet steps, adds the icon and
     * relaunches standalone — and since the web clip inherits the session
     * cookie but no client state, this stamp is the only completion signal
     * the installed app can see. Unwritten, it relaunched into a replay of
     * the tour it had just finished. First stamp wins, so no path rewrites
     * history.
     */
    public function complete(): void
    {
        $user = auth()->user();

        // Each walk stamps its OWN column. The Picks walk deliberately does
        // not touch picks_first_seen_at: that is the economy's first-visit
        // fact, and a replay from Account must never re-trigger the grant
        // hanging off it.
        $column = $this->walk === Tours::PICKS ? 'picks_tour_completed_at' : 'tour_completed_at';

        if ($user !== null && $user->{$column} === null) {
            $user->forceFill([$column => now()])->save();

            // Inside the first-stamp guard, so the counter measures readers
            // and not round trips — every later relaunch calls this too.
            // ONE emitter per signal: the two walks are two questions.
            app(RecordUxEvent::class)->handle(
                $this->walk === Tours::PICKS ? UxSignal::PicksTourDismissed : UxSignal::TourDismissed,
            );
        }
    }
}; ?>

@php
    /*
     * ONE list, read by Blade (which renders the copy blocks by index) AND
     * by Alpine (which walks the spotlights by index). It used to be typed
     * in both places with a source sweep holding them level; a second walk
     * would have been a second chance to mistype it, and a mismatch shows
     * one stop's words over another stop's highlight without erroring.
     *
     * Stops ride the list unconditionally: an anchor that is not on the
     * page — the pick'em teaser only wears `data-tour="room"` while the
     * flag is open — makes the stop step over ITSELF, which is exactly how
     * a pre-flip tour skips the beat.
     */
    $steps = $this->steps;
@endphp

{{-- `contents`: a static wrapper would claim a slot in Home's gap-6 column.
     The fixed children position against the viewport regardless. --}}
<div
    class="contents"
    data-guided-tour
    x-data="{
        open: false,
        step: 0,
        keys: @js($steps),
        box: null,
        centered: false,
        cardTop: 0,
        cardLeft: 0,

        /* The element the spotlight is currently drawn around, kept so a
           re-measure can find it again without walking the step list — and
           null on the targetless install stop, which is centered. */
        anchor: null,

        /* Live only while the tour is open; see watch()/unwatch(). */
        onMove: null,
        observer: null,

        /*
         * Whether this redraw is FOLLOWING the page rather than stepping to
         * a new target. The 300ms ease is what makes walking between coach
         * marks read as one light moving; the same ease applied to a scroll
         * correction is a spotlight lagging a third of a second behind the
         * card, which is the same complaint as being offset. So the
         * transition is bound rather than static, and tracking turns it off.
         */
        tracking: false,

        standalone() {
            return window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true
        },

        pending: false,

        /*
         * Which browser's install steps the closing stop shows inline, or
         * null when nothing was confidently detected (desktop, odd UAs) —
         * null falls back to the walkthrough screen. FxiOS before CriOS
         * before the Safari default: every iOS browser is WebKit wearing a
         * badge, so the badge token is the only signal and the order is
         * load-bearing, same as the get-app screen.
         */
        installPlatform: null,

        init() {
            const ua = navigator.userAgent
            const ios = /iPhone|iPad|iPod/.test(ua)
                || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)

            if (ios) {
                this.installPlatform = /FxiOS/.test(ua) ? 'ios-firefox' : (/CriOS/.test(ua) ? 'ios-chrome' : 'ios-safari')
            } else if (/Android/.test(ua)) {
                this.installPlatform = 'android'
            }

            /* A task, not $nextTick, for the third time and the same
               reason: a tick held across a Livewire commit would hold the
               whole tour behind it. */
            setTimeout(() => this.autoStart())
        },

        /*
         * Anything wearing `data-tour-holdoff` keeps the tour down while it
         * is visible — the signup wizard and the post-signup splash. NOT
         * offsetParent: both are `position: fixed`, and a fixed element's
         * offsetParent is null even while it fills the screen — which is
         * how the tour once launched ON TOP of the picker the moment a
         * first team landed. getClientRects() is the check that works for
         * fixed elements: x-show's `display: none` leaves it empty, a
         * visible overlay has a rect.
         */
        holdoff() {
            return Array.from(document.querySelectorAll('[data-tour-holdoff]'))
                .some((el) => el.getClientRects().length !== 0)
        },

        autoStart() {
            /* Hold back while the wizard or splash is on screen — each
               dispatches start-tour on its way out. */
            if (this.holdoff()) return

            this.startSoon()
        },

        /*
         * The beat. Every start routes through here so the reader SEES the
         * home screen before the coach marks claim it: the wizard fades
         * out, Home sits plainly visible for a moment, then the tour fades
         * in. Starting in the same frame as the wizard's close read as the
         * tour interrupting the signup rather than following it.
         */
        startSoon() {
            if (this.pending || this.open) return

            this.pending = true

            setTimeout(() => {
                this.pending = false
                this.start()
            }, 900)
        },

        start() {
            if (this.open || this.holdoff()) return

            this.open = true
            this.watch()

            /*
             * The first rect waits a turn on purpose. `x-trap.noscroll` runs
             * off the same `open` write, and Alpine flushes its effects in a
             * microtask AFTER this method returns — so a box measured inline
             * was measured before disableScrolling() put `overflow: hidden`
             * and a scrollbar's worth of `padding-right` on <html>, and the
             * reflow that followed slid the page out from under a spotlight
             * already pinned to the old numbers.
             *
             * setTimeout, NOT $nextTick, and not a style choice: Livewire
             * holds Alpine's tick stack across a commit, and a held tick is
             * only released by whatever commits next. Deferring the first
             * measurement onto it lost that race on a cold Home — the tour
             * opened with a null box, so the scrim covered the page and the
             * card it was supposed to be spotlighting sat under it, until
             * some later commit (the verify poll, thirty seconds out) let
             * the tick go. A task is nobody's to hold, and it still lands
             * in a tab that renders no frames, which rAF would not.
             */
            setTimeout(() => this.go(0, 1))
        },

        /*
         * Everything that redraws the spotlight while it is already up, and
         * nothing that MOVES the page. Re-running go() was the old answer
         * and it fed itself on a phone: scrollIntoView collapses the iOS URL
         * bar, the collapse fires resize, resize re-ran go(), which scrolled
         * again. Re-measuring is idempotent; re-navigating is not.
         */
        watch() {
            this.unwatch()

            this.onMove = () => {
                if (! this.open) return

                this.tracking = true
                this.measure()
            }

            /* Capture, because the page behind the scrim can scroll on its
               own: `overflow: hidden` on <html> is a desktop scroll lock and
               iOS Safari does not honor it for touch. A spotlight that does
               not follow that scroll is the whole reported bug. */
            window.addEventListener('scroll', this.onMove, { capture: true, passive: true })

            /* And a layout shift with no scroll and no resize at all — an
               image landing, a Livewire morph, a banner resolving its cloak —
               moves the target just as far. Observing the body catches the
               height change those make; observing <html> would not, since it
               is the viewport's height either way. */
            if (typeof ResizeObserver !== 'undefined') {
                this.observer = new ResizeObserver(() => this.onMove())
                this.observer.observe(document.body)
            }
        },

        unwatch() {
            if (this.onMove) {
                window.removeEventListener('scroll', this.onMove, { capture: true })
                this.onMove = null
            }

            if (this.observer) {
                this.observer.disconnect()
                this.observer = null
            }
        },

        target(key) {
            return Array.from(document.querySelectorAll('[data-tour]'))
                .find((el) => el.dataset.tour === key && el.offsetParent !== null) ?? null
        },

        go(index, dir) {
            if (index < 0) return

            if (index >= this.keys.length) {
                this.finish()

                return
            }

            const key = this.keys[index]

            /* The install step is deliberately targetless — a centered card.
               Inside the installed app it has nothing to sell, so it skips. */
            if (key === 'install') {
                if (this.standalone()) {
                    this.go(index + dir, dir)

                    return
                }

                /* Arriving here IS the tour completed — every informative
                   stop is behind the reader and this card is a pitch. Stamp
                   NOW, not on Done: the reader this card convinces leaves
                   through the OS share sheet without tapping another tour
                   control, and with the stamp unwritten the freshly
                   installed app relaunched straight into the tour it had
                   just finished. Idempotent server-side; first stamp wins. */
                this.$wire.complete()

                this.step = index
                this.anchor = null
                this.tracking = false
                this.box = null
                this.place()

                return
            }

            const el = this.target(key)

            /* A step whose target is not on this page steps over itself —
               the tour never points at nothing. */
            if (! el) {
                this.go(index + dir, dir)

                return
            }

            this.step = index
            this.anchor = el
            this.tracking = false
            el.scrollIntoView({ block: 'center', behavior: 'instant' })

            this.measure()
        },

        /*
         * The geometry, and ONLY the geometry — no scrolling, no step
         * change, so it is safe to run on every scroll and every reflow.
         * Rounded to whole pixels because the ring is drawn on the device
         * grid: a rect landing on a half pixel put a soft edge either side
         * of a 2px ring, which reads as the highlight not quite containing
         * the card it is around.
         */
        measure() {
            if (this.anchor === null) return

            /* Gone from the page under us — a morph can take the target
               away. Nothing to point at, so the tour stops pointing rather
               than framing whatever moved into its coordinates. */
            if (! this.anchor.isConnected || this.anchor.offsetParent === null) {
                this.box = null
                this.place()

                return
            }

            const r = this.anchor.getBoundingClientRect()

            this.box = {
                top: Math.round(r.top - 8),
                left: Math.round(Math.max(r.left - 8, 8)),
                width: Math.round(Math.min(r.width + 16, window.innerWidth - 16)),
                height: Math.round(r.height + 16),
            }

            this.place()
        },

        place() {
            if (! this.box) {
                this.centered = true

                return
            }

            this.centered = false

            const width = Math.min(window.innerWidth - 32, 384)
            const below = this.box.top + this.box.height + 12

            this.cardTop = Math.round(below)
            this.cardLeft = Math.round(Math.min(Math.max(16, this.box.left), Math.max(16, window.innerWidth - width - 16)))

            /* Correct with the card's real height once it has rendered —
               above the spotlight when below would run off screen. A task
               rather than $nextTick for the same reason start() uses one:
               Livewire holds Alpine's tick stack across a commit, and a
               correction that arrives whenever the next commit happens is a
               card left hanging off the bottom of the screen until then. */
            setTimeout(() => {
                const h = this.$refs.card?.offsetHeight ?? 0

                if (this.box && below + h + 16 > window.innerHeight) {
                    this.cardTop = Math.round(Math.max(16, this.box.top - h - 12))
                }
            })
        },

        next() { this.go(this.step + 1, 1) },
        back() { this.go(this.step - 1, -1) },

        async finish() {
            this.open = false
            this.anchor = null
            this.unwatch()

            /*
             * Home holds the verify callout down for the length of the walk,
             * so the end of the walk is what puts it back — and the order
             * here is the whole of why it works. Home's showTour reads
             * hasToured() from the database, so the announcement has to
             * follow the stamp: dispatching first pooled both calls into one
             * round trip, Home re-rendered against a row not yet written,
             * and the nudge it was told to restore stayed hidden.
             *
             * The dispatch belongs on THIS exit rather than in complete(),
             * which also fires on merely arriving at the install stop —
             * refreshing Home there would shift the page behind a scrim the
             * reader is still standing in front of.
             */
            await this.$wire.complete()

            this.$dispatch('tour-finished')
        },
    }"
    x-on:start-tour.window="startSoon()"
    {{-- Chromium announces a finished install (`appinstalled`, relayed by
         app.js). The card closes as installed rather than keeping its pitch
         up over the install animation; iOS never fires this and is covered
         by the arrival stamp in go(). --}}
    x-on:cfb:install-done.window="if (open) finish()"
    x-on:keydown.escape.window="if (open) finish()"
    {{-- Re-measure, never re-navigate: go() scrolls, and on a phone the
         scroll collapses the URL bar, which fires resize, which would run
         go() again. --}}
    x-on:resize.window="if (open) measure()"
>
    {{-- The spotlight: one element whose box-shadow IS the scrim, so the
         cutout needs no SVG mask, and moving between targets is a plain CSS
         transition. The accent ring is the pointer. --}}
    <div
        x-cloak
        x-show="open && box !== null"
        x-transition.opacity.duration.300ms
        x-bind:style="box === null ? '' : ('top:' + box.top + 'px;left:' + box.left + 'px;width:' + box.width + 'px;height:' + box.height + 'px')"
        x-bind:class="tracking ? '' : 'transition-all duration-300'"
        class="fixed z-50 rounded-xl ring-2 ring-blue-500 [box-shadow:0_0_0_200vmax_rgb(9_9_11_/_0.7)]"
        aria-hidden="true"
    ></div>

    {{-- Targetless steps still dim the page. --}}
    <div
        x-cloak
        x-show="open && box === null"
        x-transition.opacity.duration.300ms
        class="fixed inset-0 z-50 bg-zinc-950/70"
        aria-hidden="true"
    ></div>

    <div
        x-cloak
        x-show="open"
        x-transition.opacity.duration.300ms
        x-ref="card"
        x-trap.noscroll="open"
        x-bind:class="(centered ? 'top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 ' : '') + (tracking ? '' : 'transition-all duration-300')"
        x-bind:style="centered ? '' : ('top:' + cardTop + 'px;left:' + cardLeft + 'px')"
        {{-- max-h + vertical scroll: the install stop carries a full steps
             card, which on a short phone can outgrow the viewport. --}}
        class="fixed z-50 flex max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-sm flex-col gap-3 overflow-y-auto rounded-xl bg-white p-4 shadow-xl ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-700"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $walk === 'picks' ? 'Picks tour' : 'App tour' }}"
    >
        @foreach ($steps as $i => $key)
            @php
                /*
                 * The picks stop must never promise what is already there:
                 * once the flag opens, "Picks are coming" walked to the
                 * center tab is the tour lying on launch day. The branch
                 * reads the commit-11 CONFIG mirror, never
                 * Feature::active() — Pennant persists resolved values, so
                 * the flag flip would leave this stop stale per user until
                 * a purge.
                 */
                $copyKey = $key === 'picks'
                        && (config('cfb.pickem_open') === true || (bool) auth()->user()?->isAdmin())
                    ? 'picks_live'
                    : $key;
            @endphp
            <div x-show="step === {{ $i }}" wire:key="tour-step-{{ $key }}" class="flex flex-col gap-1">
                <flux:heading size="lg">{{ App\Support\Voice::line("tour.{$copyKey}.heading") }}</flux:heading>

                <flux:subheading>
                    @if ($key === 'search' && $this->searchTeam !== null)
                        {{-- The example is THEIR team — see the Voice map for
                             why it is never a canned school. --}}
                        {{ App\Support\Voice::line('tour.search.body_team', [
                            'prefix' => Illuminate\Support\Str::substr($this->searchTeam, 0, 3),
                            'team' => $this->searchTeam,
                        ]) }}
                    @else
                        {{ App\Support\Voice::line("tour.{$copyKey}.body") }}
                    @endif

                    @if ($key === 'wallet' && $this->seeded)
                        {{ App\Support\Voice::line('tour.wallet.seeded', ['xp' => App\Actions\GrantWalletEntry::FIRST_TEAM_XP]) }}
                    @endif
                </flux:subheading>

                @if ($key === 'room')
                    {{-- The one stop with a DOOR: seating the reader in a
                         contest is the first-week retention hinge, so the
                         card offers the walk, not just the words. Stamp
                         complete on the way out — a reader this button
                         convinces leaves the tour through it.

                         WHERE it goes depends on the walk, because a button
                         to the screen you are standing on is a dead button:
                         from Home it opens Picks, and from Picks — where the
                         spotlight is already on the Lobby door — it opens
                         the store itself. --}}
                    <flux:button
                        :href="route($walk === 'picks' ? 'pickem.lobby' : 'pickem.home')"
                        wire:navigate
                        x-on:click="$wire.complete()"
                        variant="primary"
                        size="sm"
                        class="mt-1 self-start"
                    >
                        Take me there
                    </flux:button>
                @endif

                @if ($key === 'install')
                    {{-- The detected browser's steps land right in the card:
                         the pitch says NOW, so the how is one glance away
                         rather than a page away. Detection can be wrong (user
                         agents lie), so a quiet path to the full walkthrough
                         and its switcher rides underneath. --}}
                    @foreach (['ios-safari', 'ios-chrome', 'ios-firefox', 'android'] as $guide)
                        <div x-cloak x-show="installPlatform === '{{ $guide }}'" wire:key="tour-guide-{{ $guide }}" class="mt-2">
                            <x-install-guide :platform="$guide" />
                        </div>
                    @endforeach

                    <flux:link
                        x-cloak
                        x-show="installPlatform !== null"
                        :href="route('get-app')"
                        wire:navigate
                        x-on:click="finish()"
                        class="mt-1 text-sm"
                    >
                        Different browser?
                    </flux:link>

                    {{-- Nothing confidently detected: the walkthrough screen
                         owns the how, and this ends the tour where it begins. --}}
                    <flux:button
                        x-cloak
                        x-show="installPlatform === null"
                        :href="route('get-app')"
                        wire:navigate
                        x-on:click="finish()"
                        variant="primary"
                        size="sm"
                        class="mt-2 w-full"
                    >
                        Show me how
                    </flux:button>
                @endif
            </div>
        @endforeach

        <div class="flex items-center justify-between gap-3">
            {{-- Skipping still stamps: a tour that keeps coming back after
                 being waved away is a tour that gets the app deleted. --}}
            <flux:button x-on:click="finish()" size="xs" variant="ghost" class="shrink-0 text-zinc-500">
                Skip
            </flux:button>

            <div class="flex items-center gap-1.5" aria-hidden="true">
                @foreach ($steps as $i => $key)
                    <span
                        wire:key="tour-dot-{{ $key }}"
                        x-bind:class="step === {{ $i }} ? 'bg-zinc-900 dark:bg-zinc-100' : 'bg-zinc-300 dark:bg-zinc-700'"
                        class="size-1.5 rounded-full transition-colors"
                    ></span>
                @endforeach
            </div>

            <div class="flex shrink-0 items-center gap-1">
                <flux:button x-cloak x-show="step > 0" x-on:click="back()" size="xs" variant="ghost">
                    Back
                </flux:button>

                <flux:button x-on:click="next()" size="xs" variant="primary">
                    <span x-text="step === keys.length - 1 ? 'Done' : 'Next'"></span>
                </flux:button>
            </div>
        </div>
    </div>
</div>
