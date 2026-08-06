<?php

use App\Models\Venue;
use Illuminate\Support\Facades\Http;

/*
 * Stadium photos are not in any feed a pregame screen can reach: the summary
 * payload carries them and an unplayed game has no summary. The URL is not
 * derivable either — measured across six venues, three answer only under
 * `day/interior`, one only under `day`, two under both, and one has none. So
 * the CDN is probed once per venue, exactly as coach headshots are.
 */

it('stores the first pattern that answers, and records the ask', function () {
    Http::fake([
        '*day/interior/3948.jpg' => Http::response('', 404),
        '*day/3948.jpg' => Http::response('', 200),
    ]);

    $venue = Venue::create(['id' => 3948, 'name' => 'Hard Rock Stadium']);

    $this->artisan('cfb:venues')->assertSuccessful();

    expect($venue->fresh()->image_url)->toContain('day/3948.jpg')
        ->and($venue->fresh()->image_checked_at)->not->toBeNull();
});

it('records the ask even when a venue has no photo, so it stops being probed', function () {
    // "Asked, and there is nothing" must be distinguishable from "never
    // asked" — otherwise 93 photoless venues are re-probed on every run.
    Http::fake(['*' => Http::response('', 404)]);

    $venue = Venue::create(['id' => 5312, 'name' => 'Obscure Field']);

    $this->artisan('cfb:venues')->assertSuccessful();

    expect($venue->fresh()->image_url)->toBeNull()
        ->and($venue->fresh()->image_checked_at)->not->toBeNull();
});

it('skips venues already checked unless forced', function () {
    Http::fake(['*' => Http::response('', 200)]);

    Venue::create(['id' => 100, 'name' => 'Done Already', 'image_checked_at' => now()]);

    $this->artisan('cfb:venues')->assertSuccessful();

    Http::assertNothingSent();

    $this->artisan('cfb:venues --force')->assertSuccessful();

    Http::assertSentCount(1);
});

it('leaves the timestamp null when the CDN errors, so the next run retries', function () {
    // A transient failure must not permanently demote a venue to "no photo".
    Http::fake(fn () => throw new RuntimeException('connection reset'));

    $venue = Venue::create(['id' => 200, 'name' => 'Flaky CDN Field']);

    $this->artisan('cfb:venues')->assertSuccessful();

    expect($venue->fresh()->image_checked_at)->toBeNull();
});
