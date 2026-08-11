<?php

use App\Http\Controllers\SmsStatusWebhookController;
use App\Http\Controllers\SmsWebhookController;
use App\Http\Controllers\UnsubscribeController;
use App\Support\Brand;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'home')->name('home');

/*
 * The install walkthrough. iOS never fires `beforeinstallprompt`, so for most
 * phones a page of per-browser steps IS the install experience — reached from
 * Home's banner, Account, and the tour, and hidden inside the installed app.
 */
Route::livewire('app', 'get-app')->name('get-app');

/*
 * Brand artefacts, generated rather than served as static files, because their
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
Route::get('site.webmanifest', fn () => response()
    ->json(Brand::manifest())
    ->header('Content-Type', 'application/manifest+json')
)->name('manifest');

Route::get('favicon.ico', function () {
    abort_if(($ico = Brand::ico()) === null, 404);

    return response($ico, 200, [
        'Content-Type' => 'image/x-icon',
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->name('favicon');

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
})->name('brand.splash');

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
 * Anything that reads or writes a user's own data sits behind BOTH `auth` and
 * `verified`. The previous version of this app declared `verified` on its route
 * group but never applied `auth`, and its verify middleware body was commented
 * out — so the entire application was publicly reachable. Both are real here.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('account', 'account')->name('account');
});

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
 * floor. Registered only in local so it can never exist in production.
 */
if (app()->isLocal()) {
    Route::view('__device', 'dev.device')->name('dev.device');
}

require __DIR__.'/auth.php';
