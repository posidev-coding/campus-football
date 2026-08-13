<?php

use App\Actions\GrantWalletEntry;
use Livewire\Component;

/**
 * The guided coach-mark tour: a spotlight that walks a brand-new user through
 * the five things worth knowing, then closes on the install — with the
 * detected browser's actual steps rendered inside the card, so "do it now"
 * is a thing the reader can literally do without leaving the spot.
 *
 * Home decides WHETHER this renders (signed in, onboarded, first team
 * followed, never toured — or an explicit replay); this component only runs
 * the walk and stamps the finish. Everything positional is client-side:
 * targets are `[data-tour]` elements, and each step spotlights whichever
 * element wearing its key is actually visible — the bottom tab below `sm`,
 * the header chip above it — so one step list serves every width.
 *
 * No requestAnimationFrame anywhere: positions are computed on step change
 * and window resize, scrolling uses `behavior: 'instant'`, and the movement
 * between spotlights is a CSS transition — so the end state is real even in
 * an automated tab that never produces a rendering frame.
 */
new class extends Component
{
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

    public function mount(): void
    {
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
     * Finishing and skipping both land here: either way the tour stays down.
     * First stamp wins, so a replay does not rewrite history.
     */
    public function complete(): void
    {
        $user = auth()->user();

        if ($user !== null && $user->tour_completed_at === null) {
            $user->forceFill(['tour_completed_at' => now()])->save();
        }
    }
}; ?>

{{-- This list is duplicated in the Alpine `keys` below and the two MUST stay
     identical — Blade renders the copy blocks by index, Alpine walks the
     spotlights by index, and a mismatch shows one step's words over another
     step's highlight without anything erroring. GuidedTourTest sweeps the
     source for parity. --}}
@php
    $steps = ['glance', 'search', 'scores', 'picks', 'wallet', 'league', 'account', 'install'];
@endphp

{{-- `contents`: a static wrapper would claim a slot in Home's gap-6 column.
     The fixed children position against the viewport regardless. --}}
<div
    class="contents"
    data-guided-tour
    x-data="{
        open: false,
        step: 0,
        keys: ['glance', 'search', 'scores', 'picks', 'wallet', 'league', 'account', 'install'],
        box: null,
        centered: false,
        cardTop: 0,
        cardLeft: 0,

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

            this.$nextTick(() => this.autoStart())
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
            this.go(0, 1)
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

                this.step = index
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
            el.scrollIntoView({ block: 'center', behavior: 'instant' })

            const r = el.getBoundingClientRect()

            this.box = {
                top: r.top - 8,
                left: Math.max(r.left - 8, 8),
                width: Math.min(r.width + 16, window.innerWidth - 16),
                height: r.height + 16,
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

            this.cardTop = below
            this.cardLeft = Math.min(Math.max(16, this.box.left), Math.max(16, window.innerWidth - width - 16))

            /* Correct with the card's real height once it has rendered —
               above the spotlight when below would run off screen. */
            this.$nextTick(() => {
                const h = this.$refs.card?.offsetHeight ?? 0

                if (this.box && below + h + 16 > window.innerHeight) {
                    this.cardTop = Math.max(16, this.box.top - h - 12)
                }
            })
        },

        next() { this.go(this.step + 1, 1) },
        back() { this.go(this.step - 1, -1) },

        async finish() {
            this.open = false

            await this.$wire.complete()
        },
    }"
    x-on:start-tour.window="startSoon()"
    x-on:keydown.escape.window="if (open) finish()"
    x-on:resize.window="if (open) go(step, 1)"
>
    {{-- The spotlight: one element whose box-shadow IS the scrim, so the
         cutout needs no SVG mask, and moving between targets is a plain CSS
         transition. The accent ring is the pointer. --}}
    <div
        x-cloak
        x-show="open && box !== null"
        x-transition.opacity.duration.300ms
        x-bind:style="box === null ? '' : ('top:' + box.top + 'px;left:' + box.left + 'px;width:' + box.width + 'px;height:' + box.height + 'px')"
        class="fixed z-50 rounded-xl ring-2 ring-blue-500 transition-all duration-300 [box-shadow:0_0_0_200vmax_rgb(9_9_11_/_0.7)]"
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
        x-bind:class="centered ? 'top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2' : ''"
        x-bind:style="centered ? '' : ('top:' + cardTop + 'px;left:' + cardLeft + 'px')"
        {{-- max-h + vertical scroll: the install stop carries a full steps
             card, which on a short phone can outgrow the viewport. --}}
        class="fixed z-50 flex max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-sm flex-col gap-3 overflow-y-auto rounded-xl bg-white p-4 shadow-xl ring-1 ring-zinc-200 transition-all duration-300 dark:bg-zinc-900 dark:ring-zinc-700"
        role="dialog"
        aria-modal="true"
        aria-label="App tour"
    >
        @foreach ($steps as $i => $key)
            <div x-show="step === {{ $i }}" wire:key="tour-step-{{ $key }}" class="flex flex-col gap-1">
                <flux:heading size="lg">{{ App\Support\Voice::line("tour.{$key}.heading") }}</flux:heading>

                <flux:subheading>
                    @if ($key === 'search' && $this->searchTeam !== null)
                        {{-- The example is THEIR team — see the Voice map for
                             why it is never a canned school. --}}
                        {{ App\Support\Voice::line('tour.search.body_team', [
                            'prefix' => Illuminate\Support\Str::substr($this->searchTeam, 0, 3),
                            'team' => $this->searchTeam,
                        ]) }}
                    @else
                        {{ App\Support\Voice::line("tour.{$key}.body") }}
                    @endif

                    @if ($key === 'wallet' && $this->seeded)
                        {{ App\Support\Voice::line('tour.wallet.seeded', ['xp' => App\Actions\GrantWalletEntry::FIRST_TEAM_XP]) }}
                    @endif
                </flux:subheading>

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
