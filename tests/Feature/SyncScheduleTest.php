<?php

use App\Models\Game;
use App\Models\Season;
use App\Models\Week;
use App\Services\CfbCalendar;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
 * The schedule file loads during EVERY artisan command — including
 * package:discover on a deploy build whose database has no tables yet — so
 * nothing in it may touch the database at load time. The recruiting entries
 * once resolved `currentYear()` while the file loaded, and the deploy died
 * before migrations ran. They pass relative tokens now, and the command
 * resolves them at run time.
 */

it('schedules recruiting by relative token, never a resolved year', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn (Event $event) => $event->command ?? '')
        ->filter(fn (string $command) => str_contains($command, '--only=recruiting'))
        ->values();

    expect($commands)->toHaveCount(2)
        ->and($commands->filter(fn (string $c) => str_contains($c, '--year=current')))->toHaveCount(1)
        ->and($commands->filter(fn (string $c) => str_contains($c, '--year=next')))->toHaveCount(1);
});

describe('the nightly aggregate is scoped to the season being played', function () {
    /*
     * A finished season's totals cannot change, so recomputing all six every
     * night is ~18 season/type rounds over 305,000 box-score lines — half an
     * hour of compute, nightly, to learn what one season did yesterday. It
     * also risks outrunning the sleep timeout on a scale-to-zero app cluster
     * and being cut off mid-pass.
     */
    it('passes the relative token rather than every season', function () {
        $aggregate = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains($event->command ?? '', 'cfb:aggregate'));

        expect($aggregate)->not->toBeNull()
            ->and($aggregate->command)->toContain('--year=current');
    });

    it('resolves `current` to a season that HAS box scores', function () {
        /*
         * resultsYear(), not currentYear(). In August the season we are
         * heading into has no completed games, so aggregating it would spend
         * the whole pass writing nothing while the season that actually holds
         * numbers went stale — the same distinction that empties a dropdown
         * everywhere else in this app.
         */
        $played = Season::factory()->create([
            'year' => 2025, 'type' => Season::REGULAR,
            'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
        ]);

        $week = Week::create([
            'season_id' => $played->id, 'number' => 5, 'name' => 'Week 5',
            'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
        ]);

        Game::factory()->finished()->create([
            'season_id' => $played->id,
            'week_id' => $week->id,
        ]);

        // Scheduled but unplayed — the season a naive "current" would pick.
        Season::factory()->create([
            'year' => 2026, 'type' => Season::REGULAR,
            'start_date' => '2026-08-22', 'end_date' => '2026-12-13',
        ]);

        $this->artisan('cfb:aggregate', ['--year' => 'current'])
            ->expectsOutputToContain('zero ESPN requests')
            ->assertSuccessful();

        expect(app(CfbCalendar::class)->resultsYear())->toBe(2025);
    });

    it('still recomputes every season when no year is named', function () {
        // The backfill path, which is what a fresh seed needs.
        $this->artisan('cfb:aggregate')->assertSuccessful();
    });
});

it('schedules the live summary sweep inside the live window', function () {
    // The sweep rides the live tier's window; its own first query is the
    // guard that makes a quiet tick free.
    $sweep = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command ?? '', 'cfb:summaries:live'));

    expect($sweep)->not->toBeNull()
        ->and($sweep->expression)->toBe('*/2 * * * *');
});

it('resolves current and next against the calendar at run time', function () {
    // The season we are heading into. `compute` is pure database arithmetic
    // — zero ESPN requests — so it can carry the year assertion safely.
    Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);

    $this->artisan('cfb:sync', ['--only' => 'compute', '--year' => 'current'])
        ->expectsOutputToContain('Syncing 2025')
        ->assertSuccessful();

    $this->artisan('cfb:sync', ['--only' => 'compute', '--year' => 'next'])
        ->expectsOutputToContain('Syncing 2026')
        ->assertSuccessful();

    // A literal year and the bare default still behave as they always did.
    $this->artisan('cfb:sync', ['--only' => 'compute', '--year' => '2024'])
        ->expectsOutputToContain('Syncing 2024')
        ->assertSuccessful();

    $this->artisan('cfb:sync', ['--only' => 'compute'])
        ->expectsOutputToContain('Syncing '.config('cfb.season'))
        ->assertSuccessful();
});
