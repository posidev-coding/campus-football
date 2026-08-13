{{--
    The cold-start splash: the branded beat every launch of the installed
    app opens on — cold open, re-open, pull-to-refresh's reload — wearing
    three cards off the `splash.boot.*` deck while it holds the screen for
    a couple of seconds. Pure theater over an already-delivered document,
    and deliberately so: instantly is indistinguishable from abruptly, the
    same argument the signup splash makes at 12.5s. This one is a launch
    beat, not a milestone — it holds ~2.7s and never grows; if it ever
    feels long, cut a phrase rather than speeding the cycle.

    Whether it SHOWS is the stylesheet's decision, made before Alpine
    exists: the head stamps `data-boot` on the root pre-paint for cold
    standalone loads only, the CSS displays this element under that
    attribute, and end() removes the attribute so wire:navigate morphs
    arrive inert. Until Alpine boots the paint is the mark over the pulsing
    dots (the phrase spans are cloaked) — which reads as a native launch,
    and is also the whole show if JS dies; the stylesheet's 8s bail is the
    exit then. The phrases are shuffled server-side per load, so the deck
    is register-aware for a member and PG-13 for a guest launch.
--}}
@php
    $bootPhrases = collect(['gates', 'chains', 'headsets', 'scores', 'turf', 'replay'])
        ->shuffle()
        ->take(3)
        ->map(fn (string $key) => App\Support\Voice::line("splash.boot.{$key}"))
        ->values();
@endphp

<div
    data-boot-splash
    {{-- Forced `dark`: curtains are black in both themes, continuous with
         the ink launch image this fades in over. No `flex` utility — the
         stylesheet owns display, so nothing here can resurrect it. --}}
    class="dark fixed inset-0 z-50 flex-col items-center justify-center gap-6 bg-zinc-950 pt-[env(safe-area-inset-top)]"
    x-data="{
        show: true,
        i: 0,
        timer: null,

        begin() {
            if (! document.documentElement.hasAttribute('data-boot')) return;

            this.timer = setInterval(() => { this.i = Math.min(this.i + 1, 2) }, 750);
            setTimeout(() => this.end(), 2200);
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
    <x-brand.mark class="size-16 shrink-0" />

    {{-- Fixed-height slot, absolutely positioned spans: a long phrase can
         never shift the mark. Same crossfade grammar as the signup splash. --}}
    <div class="relative h-6 w-full">
        @foreach ($bootPhrases as $i => $phrase)
            <span
                x-cloak
                x-show="i === {{ $i }}"
                x-transition:enter.opacity.duration.400ms
                x-transition:leave.opacity.duration.200ms
                class="absolute inset-x-4 text-center text-sm font-medium text-zinc-500 dark:text-zinc-400"
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
