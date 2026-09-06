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

describe('a bundle that failed to load', function () {
    /*
     * Resource errors fire on the element and never bubble, so the window's
     * bubbling listener is deaf to them, and the symptom they leave is a page
     * of ReferenceErrors naming the bundle's globals — "$flux is not defined"
     * beside "fluxModal is not defined", which production read twice and
     * filed against the Lobby rather than against flux.min.js (CFB-46). The
     * capture-phase listener is what turns that into a report that names the
     * asset.
     */
    it('reports a script that failed to load, by URL', function () {
        $reports = pwaSeamReports('asset-script');

        expect($reports)->toHaveCount(1)
            ->and($reports[0]['message'])->toBe('Failed to load script https://campusfootball.test/flux/flux.min.js?id=1ea4120f')
            ->and($reports[0]['source'])->toBe('https://campusfootball.test/flux/flux.min.js?id=1ea4120f')
            ->and($reports[0]['kind'])->toBe(ClientError::ERROR)
            ->and($reports[0]['line'])->toBeNull();
    });

    it('reports a stylesheet the same way', function () {
        $reports = pwaSeamReports('asset-stylesheet');

        expect($reports)->toHaveCount(1)
            ->and($reports[0]['message'])->toBe('Failed to load stylesheet https://campusfootball.test/build/assets/app-abc.css');
    });

    it('leaves the window\'s own errors to the listener that already owns them', function () {
        // The capture listener hears every error event, the thrown ones
        // included; reporting those twice would burn the page's five slots
        // at double speed. One report, and it is the thrown one.
        $reports = pwaSeamReports('asset-window-error');

        expect($reports)->toHaveCount(1)
            ->and($reports[0]['message'])->toBe('boom')
            ->and($reports[0]['line'])->toBe(3);
    });
});

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

describe('a rejection nobody caught', function () {
    it('names the failure behind the two bare words, and the screen it happened on', function () {
        /*
         * The report this replaces, in full: message "Load failed", source
         * null, line null, off /groups/51 in an installed app. Safari says
         * exactly that for ANY failed fetch, so the message on its own named
         * neither the request nor the code that asked for it — the reason's
         * NAME is what says it was a fetch at all.
         */
        $reports = pwaSeamReports('rejection-fetch');

        expect($reports)->toHaveCount(1)
            ->and($reports[0]['message'])->toBe('unhandled rejection: TypeError: Load failed')
            ->and($reports[0]['message'])->not->toBe('Load failed')
            ->and($reports[0]['kind'])->toBe(ClientError::REJECTION)
            ->and($reports[0]['path'])->toBe('/groups/51')
            ->and($reports[0]['stack'])->toContain('app-abc.js')
            // A rejection has no filename; the first https frame of its stack
            // is the only thing that ever stood in for one.
            ->and($reports[0]['source'])->toBe('https://campusfootball.test/build/assets/app-abc.js');
    });

    it('omits the half the browser did not give rather than inventing one', function () {
        // The same contract failureMessage() already holds for a caught
        // rejection, now held for an uncaught one: a reason with a name and
        // no message contributes a name, and a reason with neither leaves the
        // label standing alone. "Unhandled rejection" as a fabricated message
        // was a default written where there was no data.
        expect(pwaSeamReports('rejection-name-only')[0]['message'])
            ->toBe('unhandled rejection: SecurityError');

        expect(pwaSeamReports('rejection-anonymous')[0]['message'])
            ->toBe('unhandled rejection');

        expect(pwaSeamReports('rejection-nothing')[0]['message'])
            ->toBe('unhandled rejection');
    });

    it('keeps a reason that is nothing but a string, which is all it knows', function () {
        // `Promise.reject('nope')` carries one fact and it is the message.
        expect(pwaSeamReports('rejection-string')[0]['message'])
            ->toBe('unhandled rejection: nope');
    });

    it('never files Livewire\'s own rejection as "[object Object]"', function () {
        // What an uncaught $wire.call() rejects with: a plain
        // { status, body, json, errors }, which stringifies to a placeholder
        // wearing data's clothes. Better to say nothing about the reason.
        $report = pwaSeamReports('rejection-livewire')[0];

        expect($report['message'])->toBe('unhandled rejection')
            ->and($report['message'])->not->toContain('[object Object]');
    });

    it('sends a payload the ingest endpoint actually accepts', function () {
        // The two halves are written apart and could drift apart; the path is
        // the half that only matters once it has survived the validator.
        $this->postJson(route('client-errors.store'), pwaSeamReports('rejection-fetch')[0])->assertNoContent();

        $error = ClientError::sole();

        expect($error->message)->toBe('unhandled rejection: TypeError: Load failed')
            ->and($error->path)->toBe('/groups/51');
    });
});

describe('a rejection an island caught', function () {
    it('reports under the label the island knew and the listener could not', function () {
        // window.cfbErrors.failure() is the door onto reportFailure for the
        // Blade islands — the same machine, so that a component with a
        // `.catch()` reports what it was DOING rather than what it was given.
        $reports = pwaSeamReports('island-failure');

        expect($reports)->toHaveCount(1)
            ->and($reports[0]['message'])->toBe('iconFile upload knock failed (reportRefusedUpload)')
            ->and($reports[0]['kind'])->toBe(ClientError::REJECTION)
            ->and($reports[0]['source'])->toEndWith('js/app.js');
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

describe('the upload that fails before a byte moves', function () {
    /*
     * Livewire's UploadManager calls $wire.call('_startUpload', ..) and
     * discards the promise, and the control's own error callback only fires
     * from _uploadErrored — once a transfer is already running. So a 500
     * raised INSIDE _startUpload had nothing holding it: the picker went
     * quiet and the window got an anonymous rejection.
     *
     * Driven through the real `commit` hook the seam registers, with the
     * harness standing in for Livewire's hook bus, so what is asserted is the
     * knock the seam actually makes.
     */
    it('knocks the control so the reader is told, naming the property that failed', function () {
        expect(pwaSeam('upload-start-fails')['knocks'])
            ->toBe([['reportRefusedUpload', 'iconFile']]);
    });

    it('says nothing when the commit does not name a property', function () {
        // No property, no guess: there is no <flux:error> to write to, and
        // choosing one would put the message on a control that did not fail.
        expect(pwaSeam('upload-start-fails-unnamed')['knocks'])->toBe([]);
    });

    it('leaves every other failed commit alone', function () {
        // A failed save is not a refused upload, and the hook sees every
        // commit in the application.
        expect(pwaSeam('commit-unrelated-fails')['knocks'])->toBe([]);
    });

    it('names its own failure rather than throwing inside one', function () {
        /*
         * The knock can fail too — it is another commit, and the server that
         * just 500ed is the one being asked. Rethrowing there would produce
         * exactly the anonymous rejection this seam exists to remove.
         */
        $reports = pwaSeamReports('upload-start-knock-fails');

        expect($reports)->toHaveCount(1)
            ->and($reports[0]['message'])->toBe('iconFile upload refusal could not be shown')
            ->and($reports[0]['path'])->toBe('/groups/51');
    });
});
