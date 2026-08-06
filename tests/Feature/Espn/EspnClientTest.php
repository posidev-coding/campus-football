<?php

use App\Services\Espn\EspnClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config()->set('espn.http.retry_delay_ms', 1);
    config()->set('espn.http.rate_limit', 0);
});

it('returns the decoded body on success', function () {
    Http::fake(['*' => Http::response(['count' => 3, 'items' => []])]);

    $body = app(EspnClient::class)->core('seasons/2025', ttl: 0);

    expect($body)->toBe(['count' => 3, 'items' => []]);
});

it('sends a User-Agent ESPN does not refuse', function () {
    /*
     * `site.api.espn.com` 403s a CUSTOM User-Agent. Measured live on
     * 2026-08-06, interleaved against a working agent so ordering and rate
     * effects are ruled out: curl, GuzzleHttp and python-requests all got
     * 200, while `CampusFootball/1.0 (+https://campusfootball.net)`, a bare
     * `foo/1.0` and a full Chrome string all got 403.
     *
     * The cost of getting this wrong is invisible: a 403 is not retried, the
     * client logs and returns null, and "never write a default when a feed
     * returns nothing" means `cfb:games` reports "0 changed, 1 requests" and
     * exits 0 — the scoreboard and summary feeds, which is to say the whole
     * app, quietly stopped updating while rankings and recruiting (core and
     * web hosts, unaffected) kept working.
     *
     * Pinned as a SHAPE rather than a literal, so the env override stays
     * usable if their policy shifts again: a product name here is the
     * regression.
     */
    Http::fake(['*' => Http::response(['ok' => true])]);

    app(EspnClient::class)->site('scoreboard', ttl: 0);

    Http::assertSent(function (Request $request) {
        $agent = $request->header('User-Agent')[0] ?? '';

        return str_contains($agent, 'GuzzleHttp')
            || str_contains($agent, 'curl')
            || str_contains($agent, 'python-requests');
    });
});

it('returns null on 404 rather than throwing', function () {
    // ESPN 404s constantly for valid requests — a freshman with no stats, an
    // offseason injuries list. That must be an ordinary "no data" answer.
    Http::fake(['*' => Http::response('', 404)]);

    expect(app(EspnClient::class)->core('athletes/999999', ttl: 0))->toBeNull();
});

it('returns null instead of a non-array body', function () {
    // A 200 carrying an HTML error page is the shape that made v3 array-index
    // into null.
    Http::fake(['*' => Http::response('<html>nope</html>', 200)]);

    expect(app(EspnClient::class)->core('teams', ttl: 0))->toBeNull();
});

it('retries 500s and succeeds when the feed recovers', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push('', 500)
            ->push('', 500)
            ->push(['ok' => true], 200),
    ]);

    expect(app(EspnClient::class)->core('teams', ttl: 0))->toBe(['ok' => true]);

    Http::assertSentCount(3);
});

it('retries a 429', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push('', 429)
            ->push(['ok' => true], 200),
    ]);

    expect(app(EspnClient::class)->core('teams', ttl: 0))->toBe(['ok' => true]);

    Http::assertSentCount(2);
});

it('does not retry a 400, which would only burn the rate limit', function () {
    Http::fake(['*' => Http::response('', 400)]);

    expect(app(EspnClient::class)->core('teams', ttl: 0))->toBeNull();

    Http::assertSentCount(1);
});

it('returns null when the connection fails outright', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(app(EspnClient::class)->core('teams', ttl: 0))->toBeNull();
});

it('sends a timeout and a user agent on every request', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    app(EspnClient::class)->core('teams', ttl: 0);

    Http::assertSent(fn (Request $request) => $request->hasHeader('User-Agent', config('espn.http.user_agent')));
});

it('caches a successful response', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    $client = app(EspnClient::class);
    $client->core('teams', ttl: 600);
    $client->core('teams', ttl: 600);

    Http::assertSentCount(1);
});

it('briefly caches a miss so a dead endpoint is not re-asked all run', function () {
    Http::fake(['*' => Http::response('', 404)]);

    $client = app(EspnClient::class);
    expect($client->core('athletes/1', ttl: 600))->toBeNull();
    expect($client->core('athletes/1', ttl: 600))->toBeNull();

    Http::assertSentCount(1);
});

it('upgrades http refs to https', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    app(EspnClient::class)->ref('http://sports.core.api.espn.com/v2/thing', ttl: 0);

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://'));
});

it('walks every page of a paginated collection', function () {
    Http::fake([
        '*page=1*' => Http::response([
            'pageCount' => 2,
            'items' => [['$ref' => 'https://espn/a'], ['$ref' => 'https://espn/b']],
        ]),
        '*page=2*' => Http::response([
            'pageCount' => 2,
            'items' => [['$ref' => 'https://espn/c']],
        ]),
        'https://espn/*' => Http::response(['resolved' => true]),
    ]);

    $items = iterator_to_array(app(EspnClient::class)->paginate('seasons/2025/athletes', ttl: 0));

    expect($items)->toHaveCount(3)
        ->and($items[0])->toBe(['resolved' => true]);
});

it('counts calls so a feed run can report its request volume', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    $client = app(EspnClient::class);
    $client->core('a', ttl: 0);
    $client->core('b', ttl: 0);

    expect($client->callCount())->toBe(2);
});
