<?php

/*
 * The device preview is a local-only development tool, registered inside an
 * `app()->isLocal()` guard — so outside local it does not exist at all. That
 * absence is the property actually worth asserting.
 *
 * The view is rendered directly rather than over HTTP, because the route is
 * (correctly) unregistered in the testing environment.
 */

it('is not registered outside local', function () {
    expect(app()->environment('local'))->toBeFalse();

    expect(collect(app('router')->getRoutes())->contains(
        fn ($route) => $route->uri() === '__device'
    ))->toBeFalse();
});

it('defaults to a phone and a tablet frame', function () {
    $html = view('dev.device')->render();

    expect($html)->toContain('width="390"')
        ->and($html)->toContain('width="768"');
});

it('only points the iframe at a same-origin relative path', function () {
    // An absolute URL is forced back to a relative path, so the frame can never
    // be aimed at another host from a query string.
    request()->merge(['path' => 'https://evil.example/steal']);

    $html = view('dev.device')->render();

    expect($html)->not->toContain('src="https://evil.example');
});

it('ignores absurd widths', function () {
    request()->merge(['w' => '1,99999']);

    $html = view('dev.device')->render();

    expect($html)->not->toContain('width="99999"')
        ->and($html)->not->toContain('width="1"');
});
