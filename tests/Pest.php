<?php

use App\Support\TeamGlance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Feature tests run against a real MySQL database (campusfootball_test) rather
 * than SQLite. The schema leans on MySQL behaviour — generated columns, JSON
 * columns, enum-backed strings — and a SQLite-vs-MySQL divergence is exactly
 * the class of bug that should fail in CI rather than in production.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // TeamGlance memoizes its cached maps in a STATIC property, which outlives
    // the per-test application the array cache dies with. Without this reset a
    // test inherits the previous test's league.
    ->beforeEach(fn () => TeamGlance::flush())
    ->in('Feature');
