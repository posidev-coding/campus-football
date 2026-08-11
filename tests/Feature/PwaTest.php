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
});
