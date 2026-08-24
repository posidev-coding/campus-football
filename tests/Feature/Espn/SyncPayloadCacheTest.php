<?php

use App\Services\Espn\Sync\SyncAthleteStats;
use App\Services\Espn\Sync\SyncRecruiting;
use App\Services\Espn\Sync\SyncRosters;
use App\Services\Espn\Sync\SyncTeamStats;
use Illuminate\Support\Facades\Http;

/*
 * ONE-SHOT SYNC PAYLOADS ARE NEVER CACHED.
 *
 * EspnClient's response cache lives in the same Redis DB as the ESPN
 * limiter and the mail/SMS budget counters. A roster is half a megabyte, a
 * recruiting page carries 1,000 full documents, and the athlete stat sweep
 * walks tens of thousands of payloads — cached for 12 hours they crowd
 * that DB toward eviction, and an evicted throttle counter fails OPEN.
 * Nothing re-reads these inside their cadence, so the cache bought nothing.
 *
 * Each test runs the sync twice and counts REQUESTS: two means the payload
 * skipped the cache, one means somebody put the 12-hour ttl back.
 */

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);
});

it('re-fetches a roster rather than caching it beside the throttles', function () {
    Http::fake(['*roster*' => Http::response(['season' => ['year' => 2026], 'athletes' => []])]);

    app(SyncRosters::class)->team(61, 2026);
    app(SyncRosters::class)->team(61, 2026);

    Http::assertSentCount(2);
});

it('re-fetches a recruiting page rather than caching it', function () {
    Http::fake(['*recruiting*' => Http::response(['count' => 0, 'pageCount' => 1, 'items' => []])]);

    app(SyncRecruiting::class)->handle(2026);
    app(SyncRecruiting::class)->handle(2026);

    Http::assertSentCount(2);
});

it('re-fetches team statistics and leaders rather than caching them', function () {
    Http::fake([
        '*statistics*' => Http::response(['splits' => ['categories' => []]]),
        '*leaders*' => Http::response(['categories' => []]),
    ]);

    app(SyncTeamStats::class)->team(61, 2026);
    app(SyncTeamStats::class)->team(61, 2026);

    Http::assertSentCount(4);
});

it('re-fetches an athlete career log rather than caching it', function () {
    Http::fake(['*statisticslog*' => Http::response(['entries' => []])]);

    app(SyncAthleteStats::class)->refreshCareer(4426333);
    app(SyncAthleteStats::class)->refreshCareer(4426333);

    Http::assertSentCount(2);
});
