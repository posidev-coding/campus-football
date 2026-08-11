<?php

use Livewire\Component;

/**
 * The guided coach-mark tour: a spotlight that walks a brand-new user through
 * the five things worth knowing, then offers the home-screen install.
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

@php
    $steps = ['glance', 'search', 'scores', 'league', 'account', 'install'];
@endphp

{{-- `contents`: a static wrapper would claim a slot in Home's gap-6 column.
     The fixed children position against the viewport regardless. --}}
<div
    class="contents"
    data-guided-tour
    x-data="{
        open: false,
        step: 0,
        keys: ['glance', 'search', 'scores', 'league', 'account', 'install'],
        box: null,
        centered: false,
        cardTop: 0,
        cardLeft: 0,

        standalone() {
            return window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true
        },

        autoStart() {
            /* Hold back while the signup wizard is on screen — its done()
               dispatches start-tour when it closes. */
            const wizard = document.querySelector('[data-onboarding-overlay]')

            if (wizard && wizard.offsetParent !== null) return

            this.start()
        },

        start() {
            if (this.open) return

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
    x-init="$nextTick(() => autoStart())"
    x-on:start-tour.window="start()"
    x-on:keydown.escape.window="if (open) finish()"
    x-on:resize.window="if (open) go(step, 1)"
>
    {{-- The spotlight: one element whose box-shadow IS the scrim, so the
         cutout needs no SVG mask, and moving between targets is a plain CSS
         transition. The accent ring is the pointer. --}}
    <div
        x-cloak
        x-show="open && box !== null"
        x-bind:style="box === null ? '' : ('top:' + box.top + 'px;left:' + box.left + 'px;width:' + box.width + 'px;height:' + box.height + 'px')"
        class="fixed z-50 rounded-xl ring-2 ring-blue-500 transition-all duration-300 [box-shadow:0_0_0_200vmax_rgb(9_9_11_/_0.7)]"
        aria-hidden="true"
    ></div>

    {{-- Targetless steps still dim the page. --}}
    <div
        x-cloak
        x-show="open && box === null"
        class="fixed inset-0 z-50 bg-zinc-950/70"
        aria-hidden="true"
    ></div>

    <div
        x-cloak
        x-show="open"
        x-ref="card"
        x-trap.noscroll="open"
        x-bind:class="centered ? 'top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2' : ''"
        x-bind:style="centered ? '' : ('top:' + cardTop + 'px;left:' + cardLeft + 'px')"
        class="fixed z-50 flex w-[calc(100vw-2rem)] max-w-sm flex-col gap-3 rounded-xl bg-white p-4 shadow-xl ring-1 ring-zinc-200 transition-all duration-300 dark:bg-zinc-900 dark:ring-zinc-700"
        role="dialog"
        aria-modal="true"
        aria-label="App tour"
    >
        @foreach ($steps as $i => $key)
            <div x-show="step === {{ $i }}" wire:key="tour-step-{{ $key }}" class="flex flex-col gap-1">
                <flux:heading size="lg">{{ App\Support\Voice::line("tour.{$key}.heading") }}</flux:heading>
                <flux:subheading>{{ App\Support\Voice::line("tour.{$key}.body") }}</flux:subheading>

                @if ($key === 'install')
                    {{-- Ends the tour where the walkthrough begins. --}}
                    <flux:button
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
