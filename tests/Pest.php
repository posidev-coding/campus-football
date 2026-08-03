<?php

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
    ->in('Feature');
