<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

/*
 * Anything that reads or writes a user's own data sits behind BOTH `auth` and
 * `verified`. The previous version of this app declared `verified` on its route
 * group but never applied `auth`, and its verify middleware body was commented
 * out — so the entire application was publicly reachable. Both are real here.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('account', 'account')->name('account');
});

require __DIR__.'/auth.php';
