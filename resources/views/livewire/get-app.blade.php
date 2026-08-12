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
        installReady: false,
        standalone: false,

        detect() {
            this.standalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true

            const ua = navigator.userAgent
            const ios = /iPhone|iPad|iPod/.test(ua)
                || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)

            if (ios) {
                this.platform = /CriOS/.test(ua) ? 'ios-chrome' : 'ios-safari'
            } else if (/Android/.test(ua)) {
                this.platform = 'android'
            } else {
                this.platform = 'desktop'
            }

            this.installReady = window.cfbInstall?.available() ?? false
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

        {{-- The detected browser leads, but every walkthrough is a tap away:
             user agents lie, and half the readers are here for the phone in
             their other hand. Chips, the navigation idiom — wrapping, never
             scrolling. --}}
        <nav class="flex flex-wrap gap-1" aria-label="Browsers">
            @foreach ([
                'ios-safari' => 'iPhone · Safari',
                'ios-chrome' => 'iPhone · Chrome',
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

        {{-- The steps quote Apple's and Google's own labels verbatim — the
             user is hunting for those exact words, so the voice stays out of
             them entirely. --}}
        <div x-cloak x-show="platform === 'ios-safari'" data-platform="ios-safari">
            <x-install-steps :steps="[
                ['icon' => 'box-arrow-up', 'text' => 'Tap <strong>Share</strong> in Safari\'s toolbar.'],
                ['icon' => 'plus-square', 'text' => 'Scroll down and tap <strong>Add to Home Screen</strong>.'],
                ['icon' => 'phone', 'text' => 'Tap <strong>Add</strong> — the icon lands on your home screen.'],
            ]" />
        </div>

        <div x-cloak x-show="platform === 'ios-chrome'" data-platform="ios-chrome">
            <x-install-steps :steps="[
                ['icon' => 'box-arrow-up', 'text' => 'Tap <strong>Share</strong> at the top of Chrome.'],
                ['icon' => 'plus-square', 'text' => 'Tap <strong>Add to Home Screen</strong>.'],
                ['icon' => 'phone', 'text' => 'Tap <strong>Add</strong> — the icon lands on your home screen.'],
            ]" />
        </div>

        <div x-cloak x-show="platform === 'android'" data-platform="android">
            <x-install-steps :steps="[
                ['icon' => 'three-dots-vertical', 'text' => 'Open Chrome\'s menu at the top right.'],
                ['icon' => 'download', 'text' => 'Tap <strong>Add to Home screen</strong>, then <strong>Install</strong>.'],
                ['icon' => 'phone', 'text' => 'The app installs like any other — icon, full screen, the works.'],
            ]" />
        </div>

        <div x-cloak x-show="platform === 'desktop'" data-platform="desktop">
            <x-install-steps :steps="[
                ['icon' => 'download', 'text' => 'Click the <strong>install icon</strong> at the right end of the address bar.'],
                ['icon' => 'phone', 'text' => 'Or open the browser menu and choose <strong>Install app</strong>. It docks like a native app — its own window, its own icon.'],
            ]" />
        </div>
    </div>
</div>
