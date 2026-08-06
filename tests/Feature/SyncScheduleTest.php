<?php

use App\Models\Season;
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
