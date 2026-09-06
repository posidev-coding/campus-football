<?php

namespace App\Http\Middleware;

use App\Actions\RecordActivity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Count the screens people actually read — after the response has been sent,
 * once per screen, and never on the request path.
 *
 * WHY `terminate()` AND NOT A LIVEWIRE MOUNT HOOK. A `wire:navigate` hop
 * re-mounts several layout islands (the help sheet, the search palette, the
 * tour, the error beacon), so a mount hook counts three or four per screen and
 * the number it produces is a count of components. The GET underneath is
 * exactly one, whichever way the reader arrived.
 *
 * WHY A GROUP MIDDLEWARE AND NOT A ROUTE ALIAS. Forgetting an alias on a route
 * added next month is silent, and a sensor that quietly stops seeing a screen
 * is worse than one that never saw it — the total still looks like traffic.
 * The group is on by default and a new screen is counted by construction. The
 * `/ops/*` routes live outside the `web` group entirely and never reach here.
 *
 * The four conditions below are what separates "a person read a screen" from
 * "an HTTP request happened", and each of them is a category of thing the raw
 * request count would have swallowed.
 */
class RecordPageView
{
    /**
     * Route-name prefixes that are never product traffic: the admin panel and
     * Pulse are staff surfaces, Livewire's own endpoints are plumbing, and the
     * `dev.` harness is a developer looking at a phone width.
     */
    private const SKIP_PREFIXES = ['filament.', 'pulse', 'dev.'];

    /**
     * Livewire's own endpoints, matched anywhere in the name rather than at
     * the front: the update route is registered as `default-livewire.update`,
     * so a `str_starts_with` on `livewire.` reads as a match nobody wrote and
     * silently lets every component update through as a screen.
     */
    private const SKIP_CONTAINS = ['livewire.'];

    /**
     * Named routes that render or redirect but are not screens: the two
     * telemetry beacons, the offline shell the service worker serves when
     * there is no network, and the icon and manifest routes a browser fetches
     * without anybody asking it to.
     *
     * The last three answer a machine rather than a person, and they are here
     * for the SECOND reader of this list. The sensor itself would drop them at
     * the content-type check — nothing they return is HTML — but
     * `AnalyticsCatalog::routes()` walks the route table to find screens with
     * no views at all, and cannot check a response it never saw. Without them
     * an SMS webhook is reported as a dead screen and somebody is invited to
     * delete it.
     */
    private const SKIP_ROUTES = [
        'manifest', 'favicon', 'apple-touch-icon', 'offline',
        'client-errors.store', 'standalone.seen',
        'sanctum.csrf-cookie', 'webhooks.sms.inbound', 'webhooks.sms.status',
    ];

    /**
     * Is this route name one of the app's screens?
     *
     * PUBLIC because it is also the answer to "which screens is nobody
     * opening" — `AnalyticsCatalog::routes()` has to walk the route table to
     * find a screen with NO rows, since a screen nobody opened has nothing in
     * the rollup to find. Asking the sensor rather than restating its list is
     * what stops the two from drifting: a second copy would report a screen
     * dead that this class was never counting in the first place.
     */
    public static function isScreenRoute(?string $name): bool
    {
        if ($name === null || in_array($name, self::SKIP_ROUTES, true)) {
            return false;
        }

        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }

        foreach (self::SKIP_CONTAINS as $needle) {
            if (str_contains($name, $needle)) {
                return false;
            }
        }

        return true;
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->isScreen($request, $response)) {
            return;
        }

        app(RecordActivity::class)->pageView($request);
    }

    private function isScreen(Request $request, Response $response): bool
    {
        /*
         * GET only. This is what excludes every Livewire component update,
         * every `wire:poll` and every upload POST WITHOUT the sensor having to
         * know a single one of their URIs — a URI list is a thing that goes
         * stale the next time Livewire changes its endpoint.
         */
        if (! $request->isMethod('GET')) {
            return false;
        }

        /*
         * A 302 to login, a 403, a JSON manifest and a file download are not
         * screens somebody read. `isSuccessful()` is 2xx, and the content type
         * is what separates the HTML from everything else the same route table
         * serves.
         */
        if (! $response->isSuccessful() || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;
        }

        if (! self::isScreenRoute($request->route()?->getName())) {
            return false;
        }

        /*
         * A navigate hop IS a screen — it is the whole of how the app is read
         * — and it arrives as an ajax GET carrying `X-Livewire-Navigate`. Any
         * OTHER ajax GET is something the page fetched for itself, and
         * counting those would count the page's plumbing as attention.
         */
        return ! $request->ajax() || $request->hasHeader('X-Livewire-Navigate');
    }
}
