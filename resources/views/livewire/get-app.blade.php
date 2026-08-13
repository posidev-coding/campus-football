<?php

use Livewire\Component;

/**
 * The "Get the app" screen: per-browser walkthroughs for putting the PWA on a
 * home screen, because that concept is the app's job to teach, not the user's
 * job to know.
 *
 * All state is client-side — which browser this is, whether Chromium has
 * handed us an install prompt, whether we are already standalone — so the
 * class is empty on purpose: the server renders every platform's steps and
 * Alpine shows the one that applies (with a manual switcher, because user
 * agents lie and a user may be reading for their OTHER device).
 */
new class extends Component {}; ?>

<div
    x-data="{
        platform: 'other',
        detected: null,
        showAll: false,
        installReady: false,
        standalone: false,

        detect() {
            this.standalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true

            const ua = navigator.userAgent
            const ios = /iPhone|iPad|iPod/.test(ua)
                || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)

            if (ios) {
                /*
                 * FxiOS BEFORE CriOS, and both before the Safari default —
                 * every iOS browser is WebKit wearing a different badge, so
                 * the badge token is the ONLY signal and an unmatched one
                 * must fall through to Safari, never to a wrong match.
                 */
                this.platform = /FxiOS/.test(ua) ? 'ios-firefox' : (/CriOS/.test(ua) ? 'ios-chrome' : 'ios-safari')
                this.detected = this.platform
            } else if (/Android/.test(ua)) {
                this.platform = 'android'
                this.detected = 'android'
            } else {
                // Desktop or unrecognized: no confident mobile detection, so
                // the full switcher (Firefox included) is the answer.
                this.platform = 'desktop'
            }

            this.installReady = window.cfbInstall?.available() ?? false
        },

        /*
         * Confidently detected on a phone and not overridden: show ONLY that
         * platform's steps, with the switcher a tap away behind the toggle.
         */
        focused() {
            return this.detected !== null && ! this.showAll
        },

        async install() {
            await window.cfbInstall?.prompt()
        },
    }"
    x-init="detect()"
    x-on:cfb:install-ready.window="installReady = true"
    {{-- A walkthrough, not data: centred and held to a readable measure from
         `lg` rather than stretched across a monitor. Nothing is hidden by the
         narrowing, so the rule that every breakpoint is additive holds. --}}
    class="flex flex-col gap-5 lg:mx-auto lg:w-full lg:max-w-3xl"
>
    {{-- Like Scores, this screen has no section strip naming it, so it is
         allowed its visible heading — and the same mark, which retires at `sm`
         where the header's lockup takes over. --}}
    <div class="flex items-center gap-2">
        <x-brand.mark class="size-6 shrink-0 sm:hidden" />
        <flux:heading size="xl">Get the app</flux:heading>
    </div>

    {{-- Already standalone: the walkthrough would be selling a thing the
         reader is holding. Alpine rather than the CSS `data-install-only`
         hide, because this screen still owes them a state, not a blank. --}}
    <flux:callout x-cloak x-show="standalone" icon="check-circle" variant="success">
        <flux:callout.text>{{ App\Support\Voice::line('install.screen.installed') }}</flux:callout.text>
    </flux:callout>

    <div x-show="! standalone" class="flex flex-col gap-5">
        <flux:subheading>{{ App\Support\Voice::line('install.screen.heading') }}</flux:subheading>

        {{-- Chromium has handed us a real install prompt — one tap, no steps. --}}
        <div x-cloak x-show="installReady">
            <flux:button x-on:click="install()" variant="primary" class="w-full sm:w-auto">
                <flux:icon.download variant="mini" />
                Install the app
            </flux:button>
        </div>

        {{-- The switcher hides while the detected platform holds focus — one
             browser's steps beat four browsers' noise — but it is always one
             tap away behind the toggle below: user agents lie, and half the
             readers are here for the phone in their other hand. Chips, the
             navigation idiom — wrapping, never scrolling. --}}
        <nav x-cloak x-show="! focused()" class="flex flex-wrap gap-1" aria-label="Browsers">
            @foreach ([
                'ios-safari' => 'iPhone · Safari',
                'ios-chrome' => 'iPhone · Chrome',
                'ios-firefox' => 'iPhone · Firefox',
                'android' => 'Android',
                'desktop' => 'Desktop',
            ] as $key => $label)
                <button
                    type="button"
                    x-on:click="platform = '{{ $key }}'"
                    x-bind:aria-current="platform === '{{ $key }}' ? 'true' : null"
                    x-bind:class="platform === '{{ $key }}'
                        ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100'
                        : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-100'"
                    class="rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors"
                >{{ $label }}</button>
            @endforeach
        </nav>

        {{-- The steps live in x-install-guide — shared with the tour's
             install stop, so the two surfaces can never teach different
             instructions. --}}
        @foreach (['ios-safari', 'ios-chrome', 'ios-firefox', 'android', 'desktop'] as $key)
            <div x-cloak x-show="platform === '{{ $key }}'" data-platform="{{ $key }}" wire:key="guide-{{ $key }}">
                <x-install-guide :platform="$key" />
            </div>
        @endforeach

        {{-- The way back to the other walkthroughs when detection focused the
             page down to one. Plain, quiet, and always present in focused
             mode — detection is a guess wearing confidence. --}}
        <button
            x-cloak
            x-show="focused()"
            x-on:click="showAll = true"
            type="button"
            class="self-start text-sm font-medium text-blue-600 hover:underline dark:text-blue-400"
        >
            Using a different browser?
        </button>

        {{--
            The pointing cues: on a PHONE, when the platform on screen is the
            one detection actually found, a bouncing arrow floats toward the
            control the first step names. Positions are one tweakable map —
            browser chrome moves between OS versions, so these are config to
            adjust on a real device, not truths. `sm:hidden` because desktop
            chrome positions are unpredictable; `motion-safe:` respects
            reduced-motion; `pointer-events-none` so the hint never blocks a
            tap on the content beneath it.
        --}}
        @foreach ([
            'ios-safari' => ['at' => 'bottom-[calc(var(--nav-height)+env(safe-area-inset-bottom)+0.5rem)] right-5', 'direction' => 'down', 'label' => 'Share is down here'],
            'ios-chrome' => ['at' => 'top-2 right-4', 'direction' => 'up', 'label' => 'Tap Share up here'],
            'ios-firefox' => ['at' => 'top-2 left-4', 'direction' => 'up', 'label' => 'Share is up here'],
            'android' => ['at' => 'top-2 right-3', 'direction' => 'up', 'label' => 'The menu is up here'],
        ] as $platform => $cue)
            <x-install-arrow
                :platform="$platform"
                :at="$cue['at']"
                :direction="$cue['direction']"
                :label="$cue['label']"
            />
        @endforeach
    </div>
</div>
