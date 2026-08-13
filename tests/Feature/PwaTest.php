<?php

/**
 * The install story: the service worker, its offline floor, and the seams
 * between them. The worker itself runs only in a real browser, so these hold
 * the layer a feature test can hold — the artifacts and their agreements.
 */
describe('the offline page', function () {
    it('renders for a guest with no session and no auth', function () {
        $this->get(route('offline'))
            ->assertOk()
            ->assertSee('offline')
            ->assertSee('Try again');
    });

    it('references no bundled asset, so the cache is the whole dependency', function () {
        /*
         * The worker precaches exactly one URL. If this page ever grows an
         * @vite tag or a font link, it renders unstyled precisely when it is
         * needed — with no network to fetch the rest.
         *
         * No script assertion: Livewire's asset injector keeps a static that
         * leaks across the test process, so once any Livewire::test has run,
         * a script tag here reports the PREVIOUS test rather than this page.
         * A real /offline request renders no component and gets no injection.
         */
        $html = $this->get(route('offline'))->assertOk()->content();

        expect($html)->not->toContain('/build/')
            ->and($html)->not->toContain('rel="stylesheet"')
            ->and($html)->toContain('<style>');
    });
});

describe('the viewport lock', function () {
    /*
     * Installed, there is no browser chrome to un-zoom with: iOS auto-zooms a
     * focused sub-16px input and the app stays enlarged and side-scrolling
     * forever. The lock rides the shared head partial, so asserting it on one
     * screen per layout proves it everywhere.
     */
    it('pins the zoom lock on both layouts and the offline floor', function (string $path) {
        $this->get($path)
            ->assertOk()
            ->assertSee('maximum-scale=1, user-scalable=no', false)
            ->assertSee('viewport-fit=cover', false);
    })->with([
        'app layout' => '/',
        'auth layout' => '/login',
        'offline floor' => '/offline',
    ]);

    it('retires double-tap zoom in the stylesheet', function () {
        expect(file_get_contents(resource_path('css/app.css')))
            ->toContain('touch-action: manipulation');
    });
});

describe('pull to refresh', function () {
    it('rides the app layout, gated on both standalone signals', function () {
        /*
         * The gesture must engage ONLY inside the installed app — in a
         * browser tab the browser's own pull-to-refresh wins. Both signals,
         * because iOS meta-driven web clips report `browser` in the media
         * query and only set `navigator.standalone`.
         */
        $html = $this->get(route('home'))->assertOk()->content();

        expect($html)->toContain('data-pull-to-refresh')
            ->and($html)->toContain("matchMedia('(display-mode: standalone)')")
            ->and($html)->toContain('window.navigator.standalone === true');
    });

    it('stays off the auth screens, where a stray pull would eat a half-typed form', function () {
        $html = $this->get(route('login'))->assertOk()->content();

        expect($html)->not->toContain('data-pull-to-refresh');
    });
});

describe('escape hatches', function () {
    /*
     * Standalone has no back button, no address bar and no reload control:
     * any screen without an in-app way out is a trap. These pin the exits.
     */
    it('gives every auth-layout screen a depth-aware Back control', function (string $path) {
        // The same idiom as the game scorebug: our own history when there is
        // one, Home when the auth screen IS the history (a cold launch).
        $this->get($path)
            ->assertOk()
            ->assertSee('window.cfbAppDepth > 1', false)
            ->assertSee('>Back</button>', false);
    })->with([
        'login' => '/login',
        'register' => '/register',
        'forgot password' => '/forgot-password',
    ]);

    it('counts navigation depth on both layouts through the shared head', function (string $path) {
        // Defined by one layout only, the counter reset to undefined whenever
        // a cold load landed on the other, and Back fell back to Home even
        // with real history behind it.
        $this->get($path)
            ->assertOk()
            ->assertSee('window.cfbAppDepth = 0', false);
    })->with([
        'app layout' => '/',
        'auth layout' => '/login',
    ]);

    it('serves a 404 that walks the reader home', function () {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('Take me home')
            ->assertSee(route('home'));
    });

    it('renders an exit on every status page', function (string $view, string $exit) {
        // 419 is the likeliest inside an installed app: a session that sat on
        // a home screen for days, then submitted the plain logout POST.
        $this->view("errors.{$view}")->assertSee($exit);
    })->with([
        '403 offers home' => ['403', 'Take me home'],
        '419 offers a fresh page' => ['419', 'Keep going'],
        '500 offers a retry' => ['500', 'Try again'],
        '503 offers a retry' => ['503', 'Try again'],
    ]);
});

describe('the service worker', function () {
    it('ships at the public root and precaches the offline page', function () {
        // Scope: a worker served under a subpath can only control that
        // subpath, so /sw.js at the root is load-bearing, not a style choice.
        expect(public_path('sw.js'))->toBeReadableFile();

        $worker = file_get_contents(public_path('sw.js'));

        expect($worker)->toContain("OFFLINE_URL = '/offline'")
            ->and($worker)->toContain('skipWaiting');
    });

    it('never mediates Livewire or admin traffic', function () {
        $worker = file_get_contents(public_path('sw.js'));

        // A worker between Livewire and the network adds failure modes to
        // every wire:navigate hop; the bypass list is the contract.
        foreach (['/livewire', '/admin', '/broadcasting'] as $path) {
            expect($worker)->toContain("'{$path}'");
        }
    });

    it('is registered by the app bundle', function () {
        expect(file_get_contents(resource_path('js/app.js')))
            ->toContain("navigator.serviceWorker.register('/sw.js')");
    });

    it('carries the push handlers that make a tapped notification the deep link', function () {
        /*
         * notificationclick focusing/opening the installed app is the ONLY
         * real deep link an iOS home-screen web app has — and VERSION stays
         * v1 on purpose: the bump contract is scoped to caching strategy
         * and the offline page, which these handlers do not touch. A bump
         * appearing here should have to mean it.
         */
        $worker = file_get_contents(public_path('sw.js'));

        expect($worker)->toContain("addEventListener('push'")
            ->and($worker)->toContain("addEventListener('notificationclick'")
            ->and($worker)->toContain('openWindow')
            ->and($worker)->toContain("VERSION = 'v1'");
    });
});
