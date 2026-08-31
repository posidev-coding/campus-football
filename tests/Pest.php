<?php

use App\Services\CfbCalendar;
use App\Support\Brand;
use App\Support\Cadence;
use App\Support\GameRanks;
use App\Support\Navigation;
use App\Support\PickemPulse;
use App\Support\TeamGlance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/PickemFixtures.php';

/*
 * Feature tests run against a real MySQL database (campusfootball_test) rather
 * than SQLite. The schema leans on MySQL behaviour — generated columns, JSON
 * columns, enum-backed strings — and a SQLite-vs-MySQL divergence is exactly
 * the class of bug that should fail in CI rather than in production.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // TeamGlance, GameRanks and Brand memoize their cached values in STATIC
    // properties, which outlive the per-test application the array cache dies
    // with. Without this reset a test inherits the previous test's league — or,
    // for Brand, the previous test's colors and wordmark.
    ->beforeEach(function () {
        TeamGlance::flush();
        GameRanks::flush();
        Brand::flush();
        Cadence::flush();
        CfbCalendar::flush();
        Navigation::flush();
        PickemPulse::flush();
    })
    ->in('Feature');
