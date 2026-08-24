{{--
    Pull down to refresh — the installed app only.

    Standalone strips every browser reload affordance, and pulling down on a
    live scoreboard is a habit every native app has trained into everyone.
    The polling keeps a live game honest on its own; this exists because the
    hand does it anyway, and a gesture that does nothing reads as a frozen
    app. The payoff is a REAL reload: fresh HTML, fresh assets after a
    deploy, a fresh CSRF token for a session that sat on a home screen.
    The puck IS the whole refresh experience — the head's boot-splash stamp
    skips `reload` navigations, so the snap's accent spin hands over to the
    fresh page with no launch curtain in between.

    Gated at runtime on BOTH standalone signals (the media query for
    manifest-driven installs, `navigator.standalone` for iOS meta-driven web
    clips) — in a browser tab the browser's own pull-to-refresh must keep
    winning, so this engages nowhere near it.

    Every listener is passive and nothing is preventDefault()ed: scrolling
    never pays for this. On iOS the rubber band stretches underneath the
    puck, which is where a native refresh control rides anyway. The axis
    lock keeps Home's swiper and the week scroller owning their horizontal
    drags; `trapped()` keeps a pull that starts inside a sheet, an open
    dropdown or any inner scroller with that surface instead of the page.
--}}
<div
    data-pull-to-refresh
    x-data="{
        active: false,
        ready: false,
        refreshing: false,
        pull: 0,
        startX: 0,
        startY: 0,
        axis: null,

        standalone() {
            return window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;
        },

        start(event) {
            if (this.refreshing || ! this.standalone()) return;
            if (event.touches.length !== 1 || window.scrollY > 0) return;
            if (this.trapped(event.target)) return;

            this.active = true;
            this.axis = null;
            this.pull = 0;
            this.ready = false;
            this.startX = event.touches[0].clientX;
            this.startY = event.touches[0].clientY;
        },

        move(event) {
            if (! this.active || this.refreshing) return;

            let dx = event.touches[0].clientX - this.startX;
            let dy = event.touches[0].clientY - this.startY;

            if (this.axis === null && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) {
                this.axis = Math.abs(dy) > Math.abs(dx) ? 'y' : 'x';
            }

            if (this.axis !== 'y') return;

            this.pull = Math.min(Math.max(dy, 0) / 2, 88);
            this.ready = this.pull >= 64;
        },

        end() {
            if (! this.active || this.refreshing) return;

            this.active = false;

            if (! this.ready) {
                this.pull = 0;
                return;
            }

            // The snap: past the threshold the puck settles into its resting
            // spot and spins until the fresh page replaces it.
            this.refreshing = true;
            this.pull = 56;
            window.location.reload();
        },

        trapped(target) {
            if (target.closest('dialog, [popover]')) return true;

            for (let node = target; node && node !== document.body; node = node.parentElement) {
                if (node.scrollHeight > node.clientHeight + 1) {
                    let overflow = getComputedStyle(node).overflowY;

                    if (overflow === 'auto' || overflow === 'scroll') return true;
                }
            }

            return false;
        },
    }"
    x-on:touchstart.document.passive="start($event)"
    x-on:touchmove.document.passive="move($event)"
    x-on:touchend.document.passive="end()"
    x-on:touchcancel.document.passive="end()"
>
    {{-- z-50: transient overlay chrome, above the header and tab bar at 40.
         Parked above the viewport (and the status-bar inset) until pulled;
         pointer-events-none so a collapsing puck can never eat a tap. --}}
    <div
        class="pointer-events-none fixed top-[env(safe-area-inset-top)] left-1/2 z-50 flex size-9 items-center justify-center rounded-full border border-zinc-200 bg-white shadow-md dark:border-zinc-700 dark:bg-zinc-900"
        x-bind:style="{
            transform: 'translate(-50%, ' + (pull - 48) + 'px)',
            opacity: pull > 4 || refreshing ? 1 : 0,
            transition: active ? 'none' : 'transform 200ms ease, opacity 200ms ease',
        }"
        style="transform: translate(-50%, -48px); opacity: 0"
        aria-hidden="true"
    >
        {{-- The arrow winds up as the pull deepens — motion that answers the
             hand — then goes accent at the snap point and spins on release. --}}
        <span
            class="flex"
            x-bind:class="ready || refreshing ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-500 dark:text-zinc-400'"
            x-bind:style="refreshing ? '' : { transform: 'rotate(' + pull * 3 + 'deg)' }"
        >
            <flux:icon.arrow-repeat x-bind:class="refreshing && 'animate-spin'" class="size-4" />
        </span>
    </div>
</div>
