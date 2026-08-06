<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'home')->name('home');

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
 * Local-only responsive preview. Chrome will not size a window below ~600px,
 * so a real phone viewport is unreachable by resizing; an iframe has no such
 * floor. Registered only in local so it can never exist in production.
 */
if (app()->isLocal()) {
    Route::view('__device', 'dev.device')->name('dev.device');
}

require __DIR__.'/auth.php';
