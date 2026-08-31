{{--
    The cold-start splash: the branded beat every LAUNCH of the installed
    app opens on — cold open, re-open, a notification deep-link — wearing
    two cards off the `splash.boot.*` deck while it holds the screen for
    a couple of seconds. A pull-to-refresh reload is deliberately not a
    launch: the head's stamp skips `reload` navigations, so the pull's own
    spinner puck is that gesture's whole experience.
    Pure theater over an already-delivered document,
    and deliberately so: instantly is indistinguishable from abruptly, the
    same argument the signup splash makes at 12.5s. This one is a launch
    beat, not a milestone — it holds ~2.9s and never grows; if it ever
    feels long, cut a phrase rather than speeding the cycle.

    TWO cards, not three (2026-08-31): three inside the same hold gave each
    phrase 750ms, of which a 400ms crossfade was still resolving — the deck
    was being dealt faster than it could be read, which is the one thing a
    joke cannot survive. Two at 1400ms each read, and the shuffle over a
    six-card deck is what keeps a launch seen hundreds of times from going
    static. Adding a card back means growing the hold, not splitting it.

    Whether it SHOWS is the stylesheet's decision, made before Alpine
    exists: the head stamps `data-boot` on the root pre-paint for cold
    standalone loads only, the CSS displays this element under that
    attribute, and end() removes the attribute so wire:navigate morphs
    arrive inert. Until Alpine boots the paint is the LOCKUP over the
    pulsing dots (the phrase spans are cloaked) — which reads as a native
    launch, and is also the whole show if JS dies; the stylesheet's 8s bail
    is the exit then. The phrases are shuffled server-side per load, so the
    deck is register-aware for a member and PG-13 for a guest launch.
--}}
@php
    $bootPhrases = collect(['gates', 'chains', 'headsets', 'scores', 'turf', 'replay'])
        ->shuffle()
        ->take(2)
        ->map(fn (string $key) => App\Support\Voice::line("splash.boot.{$key}"))
        ->values();
@endphp

<div
    data-boot-splash
    {{-- Forced `dark`: curtains are black in both themes, continuous with
         the ink launch image this fades in over. No `flex` utility — the
         stylesheet owns display, so nothing here can resurrect it. --}}
    class="dark fixed inset-0 z-50 flex-col items-center justify-center gap-7 bg-zinc-950 pt-[env(safe-area-inset-top)]"
    x-data="{
        show: true,
        i: 0,
        timer: null,

        begin() {
            if (! document.documentElement.hasAttribute('data-boot')) return;

            this.timer = setInterval(() => { this.i = Math.min(this.i + 1, 1) }, 1400);
            setTimeout(() => this.end(), 2900);
        },

        end() {
            clearInterval(this.timer);
            this.show = false;

            setTimeout(() => document.documentElement.removeAttribute('data-boot'), 600);
        },
    }"
    x-init="begin()"
    x-show="show"
    x-transition:leave.opacity.duration.500ms
    aria-hidden="true"
>
    {{-- The glow, behind everything and out of flow. `-z-10` inside the
         curtain's own z-50 stacking context puts it over `bg-zinc-950` and
         under every in-flow child, so no sibling needs `relative`. --}}
    <div class="cfb-boot-glow pointer-events-none absolute inset-0 -z-10"></div>

    {{-- The lockup rather than the bare mark: at launch the app should say
         its own name, and this is the same object the auth header wears, one
         size up. `motion-safe:` on the entrance — the rise is decoration and
         the lockup is opaque without it. --}}
    <x-brand.lockup stacked size="lg" class="shrink-0 motion-safe:animate-boot-rise" />

    {{-- Fixed-height slot, absolutely positioned spans: a long phrase can
         never shift the lockup. Two lines' worth at `text-lg`, because the
         R deck writes sentences ("Untangling whatever the coordinator did to
         the headsets...") and a phrase that grew the slot would walk the
         lockup up the screen mid-beat. Each span FILLS the slot and centers
         its own text, so a one-liner and a two-liner sit on the same axis. --}}
    <div class="relative h-16 w-full">
        @foreach ($bootPhrases as $i => $phrase)
            <span
                x-cloak
                x-show="i === {{ $i }}"
                x-transition:enter.opacity.duration.400ms
                x-transition:leave.opacity.duration.200ms
                class="absolute inset-0 flex items-center justify-center px-6 text-center text-lg leading-tight font-semibold text-balance text-zinc-400 dark:text-zinc-300"
                wire:key="boot-{{ $i }}"
            >{{ $phrase }}</span>
        @endforeach
    </div>

    <div class="flex items-center gap-1.5" aria-hidden="true">
        <span class="size-1.5 animate-pulse rounded-full bg-zinc-300 dark:bg-zinc-700"></span>
        <span class="size-1.5 animate-pulse rounded-full bg-zinc-300 [animation-delay:200ms] dark:bg-zinc-700"></span>
        <span class="size-1.5 animate-pulse rounded-full bg-zinc-300 [animation-delay:400ms] dark:bg-zinc-700"></span>
    </div>
</div>
