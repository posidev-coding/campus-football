<?php

use App\Services\GamedayFeed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * The fixture below is the live 2026-08-24 payload's shape, dirt included:
 * an LSU matchup carrying Oklahoma's map block, alt text naming Ohio State
 * beside an LSU logo, last December's schedule and inconsistent id casing.
 * Every one of those is real, and every one is here so the tests can prove
 * we walk past it.
 */
function gamedayPayload(): array
{
    return [
        'matchups' => [
            [
                'id' => 'Clemson-vs-LSU',
                'cutoffTime' => '2026-09-05T09:00:00',
                'location' => 'Baton Rouge, LA',
                'date' => 'Saturday, September 5',
                'prefix' => 'Week 1 Live from',
                'homeTeamLogoSrc' => '/2025/lsu.png',
                'homeTeamLogoAlt' => 'Ohio State logo',
                'map' => ['locationName' => 'South Oval', 'address' => 'Norman Oklahoma', 'imageSrc' => 'ou-map.png'],
            ],
            [
                'id' => 'Ohio State vs Texas',
                'cutoffTime' => '2026-09-12T09:00:00',
                'location' => 'AUSTIN, TX',
                'date' => 'Saturday, September 12',
                'prefix' => 'Week 2 Live from',
                'map' => ['locationName' => 'Aggie Park', 'address' => 'College Station', 'imageSrc' => 'tam-map.png'],
            ],
        ],
        'schedule' => ['dates' => ['2025-12-06']],
        'videos' => ['playlist' => '2025 Heisman'],
        'instagram' => ['city' => 'Baton Rougue'],
    ];
}

it('reads four fields and walks past everything else', function () {
    $read = app(GamedayFeed::class)->matchups(gamedayPayload());

    expect($read)->toHaveCount(2)
        ->and(array_keys($read[0]))->toBe(['cutoff', 'location', 'date', 'prefix']);

    // The dirt must not have travelled. Norman is the one that would actually
    // reach the home page during an LSU week.
    $flat = json_encode($read);

    expect($flat)->not->toContain('Norman')
        ->not->toContain('Ohio State logo')
        ->not->toContain('ou-map')
        ->not->toContain('Rougue');
});

it('takes both weeks of lookahead', function () {
    $read = app(GamedayFeed::class)->matchups(gamedayPayload());

    expect($read[0]['location'])->toBe('Baton Rouge, LA')
        ->and($read[1]['location'])->toBe('AUSTIN, TX')
        ->and($read[1]['prefix'])->toBe('Week 2 Live from');
});

it('picks the matchup belonging to the Saturday being asked about', function () {
    $feed = app(GamedayFeed::class);

    expect($feed->forSaturday(gamedayPayload(), '2026-09-05')['location'])->toBe('Baton Rouge, LA')
        ->and($feed->forSaturday(gamedayPayload(), '2026-09-12')['location'])->toBe('AUSTIN, TX');
});

it('answers nothing for a Saturday the feed has not caught up to', function () {
    /*
     * THE FRESHNESS TRAP. Stale rows are hidden by booleans rather than
     * removed, so the most recent matchup is always sitting there looking
     * answerable. Rendering it as though it were this week's is the
     * no-defaults rule broken by a feed that helpfully keeps the old value.
     */
    expect(app(GamedayFeed::class)->forSaturday(gamedayPayload(), '2026-09-19'))->toBeNull();
});

it('reads the cutoff in Eastern rather than silently in UTC', function () {
    // 09:00 with no zone. Read as UTC it is 05:00 ET the same day here, but a
    // 00:30 cutoff would move to the day BEFORE and match the wrong Saturday.
    $payload = ['matchups' => [[
        'cutoffTime' => '2026-09-05T00:30:00',
        'location' => 'Baton Rouge, LA',
    ]]];

    expect(app(GamedayFeed::class)->forSaturday($payload, '2026-09-05')['location'])->toBe('Baton Rouge, LA');
});

it('drops a malformed row without losing the rest of the payload', function () {
    $payload = ['matchups' => [
        ['location' => 'Nowhere, XX'],                                  // no cutoff
        ['cutoffTime' => '2026-09-05T09:00:00'],                        // no location
        ['cutoffTime' => 'not a date', 'location' => 'Nowhere, XX'],    // unparseable
        'a string where an object should be',
        ['cutoffTime' => '2026-09-12T09:00:00', 'location' => 'AUSTIN, TX'],
    ]];

    $read = app(GamedayFeed::class)->matchups($payload);

    expect($read)->toHaveCount(1)
        ->and($read[0]['location'])->toBe('AUSTIN, TX');
});

it('returns nothing rather than guessing a URL when the feed is unconfigured', function () {
    // The path was read from a browser's network tab, not derived. An
    // unconfigured feed is a reported failure, never a plausible guess.
    config()->set('gameday.feed_url', null);
    Http::preventStrayRequests();

    expect(app(GamedayFeed::class)->payload())->toBeNull();
});

it('treats an unreachable or unhappy feed as no data', function () {
    config()->set('gameday.feed_url', 'https://promo.espn.com/collegegameday/index.json');

    Http::fake(['*' => Http::response('', 503)]);
    expect(app(GamedayFeed::class)->payload())->toBeNull();

    Http::fake(['*' => Http::response('<html>not json</html>', 200)]);
    expect(app(GamedayFeed::class)->payload())->toBeNull();

    Http::fake(fn () => throw new ConnectionException('timed out'));
    expect(app(GamedayFeed::class)->payload())->toBeNull();
});

it('fingerprints what it read, so a changed location is noticeable', function () {
    $feed = app(GamedayFeed::class);
    $matchup = $feed->forSaturday(gamedayPayload(), '2026-09-05');

    $moved = [...$matchup, 'location' => 'Norman, OK'];

    expect($feed->fingerprint($matchup))->toHaveLength(64)
        ->and($feed->fingerprint($moved))->not->toBe($feed->fingerprint($matchup));
});
