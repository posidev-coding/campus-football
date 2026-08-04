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
Route::livewire('stats', 'stats')->name('stats');
Route::livewire('leaders', 'leaders')->name('leaders');
Route::livewire('news', 'news')->name('news');
Route::livewire('bowls', 'bowls')->name('bowls');

Route::livewire('teams', 'teams')->name('teams');
Route::livewire('teams/{team}', 'team')->name('team');
Route::livewire('conferences/{conference}', 'conference')->name('conference');
Route::livewire('players/{athlete}', 'player')->name('player');
Route::livewire('recruiting/{class?}', 'recruiting')->name('recruiting');

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
