<?php

use App\Models\ClientError;
use Illuminate\Support\Facades\Process;

/*
 * The PWA seam — resources/js/app.js — is the one file in the tree with no
 * component around it and no server render to assert against, and two of its
 * paths only exist when a browser refuses something. A Pest 4 browser test is
 * what it deserves; this project has no browser plugin installed, and adding
 * Playwright to assert one string is not that trade.
 *
 * So the module is driven under node instead, by tests/pwa-seam-harness.mjs,
 * against a stubbed navigator. The point is that it IMPORTS app.js rather than
 * reading it as text: a sweep for `.catch(` would have passed over a handler
 * that reported the wrong thing, and asserting the source instead of the
 * behavior is how the bare "Rejected" shipped in the first place.
 */

/**
 * Run one scenario and hand back what the seam tried to POST.
 *
 * @return array{posts: list<array{url: string, body: array<string, mixed>}>, result: ?string}
 */
function pwaSeam(string $scenario): array
{
    $result = Process::run([
        'node',
        base_path('tests/pwa-seam-harness.mjs'),
        $scenario,
        resource_path('js/app.js'),
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

/** The reports that went to the client-error endpoint, push writes excluded. */
function pwaSeamReports(string $scenario): array
{
    return array_values(array_map(
        fn (array $post) => $post['body'],
        array_filter(pwaSeam($scenario)['posts'], fn (array $post) => $post['url'] === '/client-errors'),
    ));
}

describe('service worker registration', function () {
    it('names what failed and why instead of reporting the bare rejection', function () {
        // The bug: unguarded, this reached the unhandledrejection listener as
        // String(reason) — the single word "Rejected", with no route and no
        // cause, and it burned two of the five reports a page is allowed.
        $reports = pwaSeamReports('sw-named');

        expect($reports)->toHaveCount(1)
            ->and($reports[0]['message'])->toBe('service-worker registration failed: SecurityError: The operation is insecure.')
            ->and($reports[0]['message'])->not->toBe('Rejected')
            ->and($reports[0]['kind'])->toBe(ClientError::REJECTION)
            ->and($reports[0]['stack'])->toContain('SecurityError');
    });

    it('sources the report to the bundle the rejection never carried', function () {
        // import.meta.url, which under node is the module's own file:// URL
        // and in the browser is /build/assets/app-*.js. A rejection has no
        // filename of its own, which is why the listener has to mine a stack.
        expect(pwaSeamReports('sw-named')[0]['source'])->toEndWith('js/app.js');
    });

    it('omits the half the browser did not give rather than inventing one', function () {
        // null means no data here as everywhere else: a DOMException with a
        // name and no message contributes a name, and one with neither leaves
        // the label alone rather than padding it out with "Unknown error".
        expect(pwaSeamReports('sw-name-only')[0]['message'])
            ->toBe('service-worker registration failed: SecurityError');

        expect(pwaSeamReports('sw-anonymous')[0]['message'])
            ->toBe('service-worker registration failed');
    });

    it('reports nothing at all when registration succeeds', function () {
        expect(pwaSeamReports('sw-ok'))->toBe([]);
    });

    it('sends a payload the ingest endpoint actually accepts', function () {
        // The two halves are written apart and could drift apart. Anything the
        // validator rejects is a report the browser throws away silently.
        $report = pwaSeamReports('sw-named')[0];

        $this->postJson(route('client-errors.store'), $report)->assertNoContent();

        expect(ClientError::sole()->message)
            ->toBe('service-worker registration failed: SecurityError: The operation is insecure.');
    });
});

describe('turning push on', function () {
    it('resolves error rather than rejecting when the permission prompt refuses', function () {
        // enable() is awaited by push-banner's turnOn(), which has no catch:
        // an escaping rejection left busy stuck true and the Turn on button
        // disabled for the rest of the session.
        $run = pwaSeam('push-permission-rejects');

        expect($run['result'])->toBe('error');
    });

    it('resolves error rather than rejecting when the subscribe fails', function () {
        expect(pwaSeam('push-subscribe-rejects')['result'])->toBe('error');
    });

    it('names the failure instead of losing it to the catch', function () {
        // Swallowing would trade a useless signal for no signal. A rejected
        // subscribe is usually a wrong VAPID key, which IS a code defect.
        expect(pwaSeamReports('push-subscribe-rejects')[0]['message'])
            ->toBe('push subscription failed: AbortError: Push service unreachable');
    });

    it('still hands back the browser answer when there is nothing wrong', function () {
        // The guard must not flatten a refusal into an error: 'denied' is what
        // tells the banner to stop pitching, and 'granted' is the whole point.
        expect(pwaSeam('push-denied')['result'])->toBe('denied')
            ->and(pwaSeam('push-granted')['result'])->toBe('granted');

        expect(pwaSeamReports('push-granted'))->toBe([]);
    });
});
