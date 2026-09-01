<?php

use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\LeaveImpersonationController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SmsStatusWebhookController;
use App\Http\Controllers\SmsWebhookController;
use App\Http\Controllers\StandaloneSeenController;
use App\Http\Controllers\UnsubscribeController;
use App\Models\Contest;
use App\Models\Group;
use App\Models\User;
use App\Support\Brand;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

Route::livewire('/', 'home')->name('home');

/*
 * The install walkthrough. iOS never fires `beforeinstallprompt`, so for most
 * phones a page of per-browser steps IS the install experience — reached from
 * Home's banner, Account, and the tour, and hidden inside the installed app.
 */
Route::livewire('app', 'get-app')->name('get-app');

/*
 * Pick'em's two front doors: MY PICKS at /picks (the reader's own week)
 * and THE LOBBY at /lobby (the contest browser). Both sit OUTSIDE the
 * flag middleware and both render the coming-soon promise to guests and
 * to anyone outside the flag — the flag decides what a screen shows, not
 * whether it exists.
 *
 * Deliberately NO redirect between them. /picks used to 301 to /lobby;
 * browsers cache a 301 forever, so a redirect the other way would loop
 * for every dev browser holding the old one. Two real 200s, no hop.
 */
Route::livewire('picks', 'pickem-home')->name('pickem.home');
Route::livewire('lobby', 'lobby')->name('pickem.lobby');
Route::permanentRedirect('picks/groups', 'picks')->name('picks.groups');

/*
 * The invite landing: /join/{CODE}, the URL a group actually travels by.
 * Public like the lobby and for the same reason — the whole point is a
 * GUEST tapping a friend's link and seeing what they were invited to
 * before any wall; the flag check lives in mount() (it scopes to the
 * user, so middleware would 400 every guest), and joining itself rides
 * JoinGroup's own gates.
 *
 * The code is OPTIONAL, because /join?by=handle is the app invite — a
 * personal link with no group behind it, for the message the inviter
 * actually wants to send. ONE route and one name, so every existing
 * route('pickem.join', ['code' => …]) is untouched and the codeless form
 * is the same call with the code left out.
 */
Route::livewire('join/{code?}', 'join')->name('pickem.join');

/*
 * Brand artifacts, generated rather than served as static files, because their
 * contents are editable from the App Branding admin page.
 *
 * A second copy of the icon list is how a home-screen icon ends up disagreeing
 * with the tab icon, so both of these read App\Support\Brand — the same
 * resolver the layouts and the Filament panel use.
 *
 * `public/favicon.ico` was a tracked ZERO-byte file, and had to be deleted for
 * this route to be reachable at all: the web server's try_files serves a real
 * file before it ever reaches PHP, so an empty one shadows the route silently.
 */
/*
 * The five asset routes below skip the session stack. They set
 * `Cache-Control: public`, and StartSession queues a Set-Cookie — a pair
 * every CDN and edge refuses to cache, so each request also paid two
 * session queries for an icon. No asset here reads auth.
 */
Route::get('site.webmanifest', fn () => response()
    ->json(Brand::manifest())
    ->header('Content-Type', 'application/manifest+json')
    ->header('Cache-Control', 'public, max-age=86400')
)->name('manifest')->withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    // The CSRF layer BOTH validates (moot on GET) and sets the XSRF
    // cookie — the other half of the Set-Cookie these routes must not send.
    PreventRequestForgery::class,
    AddQueuedCookiesToResponse::class,
]);

Route::get('favicon.ico', function () {
    abort_if(($ico = Brand::ico()) === null, 404);

    return response($ico, 200, [
        'Content-Type' => 'image/x-icon',
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->name('favicon')->withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    // The CSRF layer BOTH validates (moot on GET) and sets the XSRF
    // cookie — the other half of the Set-Cookie these routes must not send.
    PreventRequestForgery::class,
    AddQueuedCookiesToResponse::class,
]);

/*
 * The ROOT-path apple-touch-icon convention. The layout links the real icon,
 * but Firefox on iOS ignores that link when building a home-screen web clip
 * and iOS falls back to probing the domain root — /apple-touch-icon.png,
 * plus -precomposed and sized variants — and a 404 there is the generic gray
 * letter tile. Both routes answer every probe with the branded 180px PNG
 * (iOS scales down happily), served through Brand so a rebrand reaches it.
 * Two routes rather than one optional segment, because Laravel only allows
 * an optional parameter at the very end of a URI and `.png` has to follow it.
 */
$appleTouchIcon = function () {
    abort_if(($png = Brand::bytes('apple-touch')) === null, 404);

    return response($png, 200, [
        'Content-Type' => 'image/png',
        'Cache-Control' => 'public, max-age=86400',
    ]);
};

Route::get('apple-touch-icon.png', $appleTouchIcon)->name('apple-touch-icon')->withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    // The CSRF layer BOTH validates (moot on GET) and sets the XSRF
    // cookie — the other half of the Set-Cookie these routes must not send.
    PreventRequestForgery::class,
    AddQueuedCookiesToResponse::class,
]);
Route::get('apple-touch-icon-{variant}.png', $appleTouchIcon)->where('variant', '[a-z0-9x-]+')->withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    // The CSRF layer BOTH validates (moot on GET) and sets the XSRF
    // cookie — the other half of the Set-Cookie these routes must not send.
    PreventRequestForgery::class,
    AddQueuedCookiesToResponse::class,
]);

/*
 * iOS launch screens, one per declared device size — see Brand::SPLASH. The
 * spec is validated against that list rather than parsed permissively, so
 * this cannot be asked to render arbitrary-size images.
 */
Route::get('brand/splash/{spec}.png', function (string $spec) {
    abort_unless(preg_match('/^(\d+)x(\d+)@(\d)$/', $spec, $m) === 1, 404);

    $size = [(int) $m[1], (int) $m[2], (int) $m[3]];
    abort_unless(in_array($size, Brand::SPLASH, true), 404);

    abort_if(($png = Brand::splash(...$size)) === null, 404);

    return response($png, 200, [
        'Content-Type' => 'image/png',
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->name('brand.splash')->withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    // The CSRF layer BOTH validates (moot on GET) and sets the XSRF
    // cookie — the other half of the Set-Cookie these routes must not send.
    PreventRequestForgery::class,
    AddQueuedCookiesToResponse::class,
]);

/*
 * The service worker's offline fallback — precached by public/sw.js at
 * install and served for any navigation the network cannot answer. A plain
 * self-contained view, outside every layout on purpose.
 */
Route::view('offline', 'offline')->name('offline');

/*
 * Public sports data. These are read-only and served from cache, so a cold
 * visitor never pays a database wake on Laravel Cloud's scale-to-zero MySQL.
 *
 * The one exception is the game page: a box score exists nowhere but ESPN's
 * `summary` payload, so that screen can trigger a fetch. It is throttled per
 * GAME rather than per viewer — see SyncGameSummary.
 */
Route::livewire('scoreboard', 'scoreboard')->name('scoreboard');
Route::livewire('games/{game}', 'game')->name('game');
Route::livewire('standings', 'standings')->name('standings');
Route::livewire('rankings', 'rankings')->name('rankings');
// One Stats screen, split by a Team/Players sub-toggle. `leaders` used to be
// its own route and is gone: it was the same screen reading a different table.
Route::livewire('stats', 'stats')->name('stats');
Route::livewire('news', 'news')->name('news');
/*
 * Article bodies are read HERE rather than on espn.com. Like the game page,
 * this screen can trigger an ESPN request — the body exists in exactly one
 * payload — and it is bounded the same way: fetched once per article, ever,
 * throttled per article rather than per viewer. See SyncArticleStory.
 *
 * Routed by id: `articles` has no slug, and ESPN headlines collide freely.
 */
Route::livewire('news/{article}', 'article')->name('article');

/*
 * No `bowls` route. The postseason is two entries at the end of the scoreboard's
 * week scroller — BOWLS and CFP — because ESPN publishes it as part of the same
 * season and that is where a reader scrolls to find it.
 */

/*
 * Search lives in the bar at the top of Home now, not on a tab. This route
 * survives for deep links and shared URLs — it renders the same shared
 * partial the Home panel does, backed by the same App\Support\Search.
 */
Route::livewire('search', 'search-page')->name('search');

Route::livewire('teams', 'teams')->name('teams');
Route::livewire('teams/{team}', 'team')->name('team');
Route::livewire('conferences/{conference}', 'conference')->name('conference');
// Index then detail, like teams. No collision — they differ in segment count.
Route::livewire('players', 'players')->name('players');
Route::livewire('players/{athlete}', 'player')->name('player');

// By id, not slug, matching athletes: coaches have no slug column, and the
// table grows as historical staffs sync, so slugs would be a collision
// waiting to happen (326 athlete slugs already collide).
Route::livewire('coaches/{coach}', 'coach')->name('coach');
Route::livewire('recruiting/{year?}', 'recruiting')->name('recruiting');

/*
 * `auth` is real here — v3's lesson was a "protected" group that declared
 * `verified` but never applied `auth`, with the verify middleware body
 * commented out, so every "protected" page was publicly reachable.
 *
 * `verified` is deliberately NOT applied: verification is reserved for
 * PARTICIPATION — Pick'em actions and XP earning — not for reading your own
 * settings. An unverified account is nudged on Home and Account, rewarded
 * (Tallboy + XP) the moment it verifies, and pruned after
 * User::VERIFICATION_GRACE_DAYS instead of being walled out on day one.
 */
Route::middleware(['auth'])->group(function () {
    Route::livewire('account', 'account')->name('account');

    /*
     * The exit from an impersonated session, posted from the amber banner the
     * product layout renders while `impersonator_id` is in the session.
     *
     * A POST because it changes who is signed in — so it rides CSRF like any
     * other state change — and inside the auth group because the only session
     * that can leave an impersonation is one that is inside it. There is no
     * matching ENTRY route: impersonation starts from the admin panel's own
     * action, where the guards live.
     */
    Route::post('impersonation/leave', LeaveImpersonationController::class)
        ->name('impersonation.leave');

    /*
     * The inbox. Signed-in only, and inside the auth group for the obvious
     * reason: it is nothing but this reader's own notifications.
     *
     * The COMPONENT is `inbox`, not `notifications` — Filament registers a
     * Livewire component under that name and the admin panel's toast stack
     * wins the lookup, so the screen renders as an empty notification tray.
     * The route keeps the honest name.
     */
    Route::livewire('notifications', 'inbox')->name('notifications');

    /*
     * Pick'em's group screens, behind the `pickem` flag while the phase
     * builds out. `auth` but never `verified`: reading is open to any
     * signed-in member — the verified gate lives inside the mutating
     * Actions (CreateGroup, JoinGroup, ...), where a public Livewire
     * method cannot route around it.
     */
    Route::middleware([EnsureFeaturesAreActive::using('pickem')])->group(function () {
        // The Picks area's other two sections. `/lobby` itself stays
        // public above (it wears the coming-soon outside the flag);
        // these two have no promise to keep for outsiders.
        Route::livewire('lobby/leaderboard', 'pickem-leaderboard')->name('pickem.leaderboard');
        Route::livewire('lobby/history', 'pickem-history')->name('pickem.history');

        /*
         * The clubhouse claims the short URL — a group is a place people
         * return to all season, not a page inside the Picks screen. The
         * old nested path survives as a permanent redirect so anything
         * that learned it (a shared link, a bookmark) still arrives.
         *
         * `groups/new` MUST register before `groups/{group}`, or the
         * wizard is swallowed as a route-model binding for a group
         * named "new".
         */
        Route::livewire('groups/new', 'group-create')->name('pickem.create');
        Route::livewire('groups/{group}', 'group')->name('pickem.group');
        Route::livewire('groups/{group}/build', 'slate-builder')->name('pickem.build');

        /*
         * The thread's own door (2026-08-30): one address for both kinds
         * — Talk is reached from inside a clubhouse, never by shared
         * link, so the kind is already resolved on arrival. Members only;
         * the screen 403s everyone else.
         */
        Route::livewire('groups/{group}/talk', 'group-talk')->name('pickem.talk');

        /*
         * HOW THIS WORKS: the Picks area's reference screen. A side room
         * off My Picks rather than a fifth chip in a four-chip section
         * strip — it is read once and returned to rarely, and the strip is
         * for the places somebody moves between every week.
         *
         * Inside the flag with the rest of them: outside it there is no
         * economy to explain and /picks keeps its coming-soon promise.
         */
        Route::livewire('picks/how', 'picks-how')->name('pickem.how');

        /*
         * A public room is the same clubhouse component wearing its own
         * address — the screen redirects each kind to its home, so a
         * shared link always reads /contests/... for a room and
         * /groups/... for a group.
         */
        Route::livewire('contests/{group}', 'group')->name('pickem.room');

        /*
         * Plain RedirectResponse rather than the redirect() helper: an
         * aborted Livewire mount leaves Livewire's own redirector bound
         * in the container, and the helper would hand the router that
         * object instead of a response on the next request in-process.
         */
        Route::get('picks/groups/{group}', fn (Group $group) => new RedirectResponse(route('pickem.group', $group), 301))
            ->name('picks.group');

        // One mode per group makes the contest redundant in the URL; the
        // old address walks to the group's wizard.
        Route::get('picks/build/{contest}', fn (Contest $contest) => new RedirectResponse(route('pickem.build', $contest->group_id), 301))
            ->name('picks.build');
    });

    /*
     * The install-signal beacon. POSTed once per session by the layout's
     * beacon island when it finds itself running standalone — the stamp
     * behind User::hasInstalled(). Idempotent by design; see the controller.
     */
    Route::post('standalone-seen', StandaloneSeenController::class)->name('standalone.seen');

    /*
     * This device's push subscription. Store fires the welcome push on a
     * genuinely new endpoint; destroy is the Account switch's off position.
     * The subscription is the consent — see the controller.
     */
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');
});

/*
 * Where the browser reports its own JavaScript errors.
 *
 * Open to guests, because a broken PUBLIC page is the report worth having
 * most — Home, a game, the lobby and the invite landing all render without a
 * session, and no server-side monitor sees any of it. Bounded three ways: this
 * throttle, the Redis dedupe behind the controller, and a declared width on
 * every column it writes. Thirty a minute is far above a real page and far
 * below a loop worth worrying about.
 */
Route::post('client-errors', ClientErrorController::class)
    ->middleware('throttle:30,1')
    ->name('client-errors.store');

/*
 * One-click unsubscribe, and deliberately OUTSIDE the auth group.
 *
 * Somebody who wants the emails to stop is the least likely person in the world
 * to log in to make that happen, and a login wall between them and the button
 * is how an unsubscribe becomes a spam report instead. The signature is the
 * authentication: it is bound to the user id, it cannot be edited into somebody
 * else's, and `signed` rejects a tampered one with a 403.
 *
 * POST as well as GET, because RFC 8058 List-Unsubscribe-Post is what makes
 * Gmail and Apple Mail show their own native unsubscribe control — and their
 * one-click sends a POST with no session behind it.
 */
Route::match(['get', 'post'], 'unsubscribe/{user}', UnsubscribeController::class)
    ->middleware('signed')
    ->name('newsletter.unsubscribe');

/*
 * Inbound SMS from Vonage — a STOP reply, in practice.
 *
 * Unauthenticated because a webhook has no session, and safe to leave that way
 * because the endpoint can only ever turn SMS OFF: forging it achieves what the
 * user could have asked for anyway. Turning it back on requires signing in.
 *
 * GET as well as POST because Vonage's inbound method is an account setting and
 * defaults have moved over the years; answering both means the setting cannot
 * be the reason opt-outs silently stop working.
 */
Route::match(['get', 'post'], 'webhooks/sms/inbound', SmsWebhookController::class)
    ->name('webhooks.sms.inbound');

/*
 * Delivery receipts. Vonage requires a status URL on an application, and it
 * earns its place: the send API returns success for a message the carrier will
 * go on to drop, so this is the only signal that separates "accepted and
 * billed" from "actually arrived".
 *
 * Writes nothing, which makes it the safest endpoint in the app to leave open —
 * a forged receipt costs one log line.
 */
Route::match(['get', 'post'], 'webhooks/sms/status', SmsStatusWebhookController::class)
    ->name('webhooks.sms.status');

/*
 * Local-only responsive preview. Chrome will not size a window below ~600px,
 * so a real phone viewport is unreachable by resizing; an iframe has no such
 * limit. Registered only in local so it can never exist in production.
 */
if (app()->isLocal()) {
    Route::view('__device', 'dev.device')->name('dev.device');

    /*
     * Signs the browser in as a fixture user and lands on the harness, so
     * member-only chrome (tour, verify nudge, wallet) is reachable at phone
     * widths without typing a password into the real login form. Local-only
     * for the same reason as the harness itself: registered here, it can
     * never exist in production.
     */
    Route::get('__device/act-as/{user}', function (User $user) {
        auth()->login($user);
        request()->session()->regenerate();

        return redirect()->route('dev.device', request()->only(['path', 'w', 'h', 'dark']));
    })->name('dev.act-as');
}

require __DIR__.'/auth.php';
