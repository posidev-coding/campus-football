<?php

use Illuminate\Support\Facades\File;

/*
 * Flux registers itself on `alpine:init` and on nothing else, so it exists
 * only if flux.js executed before Livewire started Alpine. When it did not,
 * every Flux expression on the page throws at once — "$flux is not defined"
 * from the layout's theme-color effect, "fluxModal is not defined" from the
 * search palette — and production has read exactly that pair, twice, on two
 * screens (CFB-46). Nothing in the app reproduces it: a cold load, a
 * Livewire.navigate hop and Back/Forward are all clean at 390 and 1024. These
 * pin the two things that keep it that way, so a refactor cannot reopen it.
 */

describe('boot order', function () {
    it('loads Flux before Livewire on both layouts', function (string $path) {
        // Livewire injects its own tag before </body>; @fluxScripts must sit
        // ahead of it, or Alpine starts with no Flux to register. Both tags
        // are navigate-once, so on a hop between these layouts neither runs
        // again and the registrations from the cold load carry over.
        $html = $this->get($path)->assertOk()->getContent();

        $flux = strpos($html, '/flux/flux');
        $livewire = strpos($html, '/livewire.');

        expect($flux)->not->toBeFalse()
            ->and($livewire)->not->toBeFalse()
            ->and($flux)->toBeLessThan($livewire);
    })->with(['the app layout' => '/', 'the auth layout' => '/login']);
});

describe('the bundle boundary', function () {
    /*
     * Livewire skips a navigate-once body script only when the PREVIOUS body
     * carried the same tag. A hop from a Livewire page without Flux into one
     * with it would run flux.js after Alpine had started, where its
     * alpine:init listener fires never — the admin panel is that page, and it
     * crosses on plain anchors for exactly this reason.
     */
    it('never leaves the admin panel by wire:navigate', function () {
        // Comments stripped first: the partial that crosses says in its own
        // docblock that it never uses wire:navigate, and a sweep that fails
        // on the sentence explaining the rule is a sweep somebody deletes.
        $offenders = collect(File::allFiles(resource_path('views/filament')))
            ->filter(fn ($file) => str_contains(preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents()), 'wire:navigate'))
            ->map(fn ($file) => $file->getRelativePathname())
            ->values()
            ->all();

        expect($offenders)->toBe([]);
    });

    it('keeps the admin panel out of SPA mode', function () {
        // The provider's own comment says "NO ->spa()", so the sweep reads
        // the code with the comments removed.
        $provider = File::get(app_path('Providers/Filament/AdminPanelProvider.php'));
        $code = preg_replace(['#/\*.*?\*/#s', '#//.*#'], '', $provider);

        expect($code)->not->toContain('->spa(');
    });
});
