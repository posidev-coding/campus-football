<?php

use App\Support\TelemetrySnapshot;

/*
 * The advisor's brief, checked against the payload it describes.
 *
 * The maintenance advisor is a Claude Code routine with no database access. It
 * reads `SKILL.md` and it reads the snapshot, and the section table in that
 * file is the ONLY thing telling it what any key means. A section that ships
 * undocumented is not a small gap: the routine either ignores a number nobody
 * told it about, or — worse — decides for itself what `stickiness_28d` is and
 * files a finding on its own guess.
 *
 * So this is a sweep rather than a list. Adding a sixth section to the
 * snapshot fails here until the row that explains it exists, and no amount of
 * remembering is involved.
 */

it('documents every top-level snapshot key in the advisor skill', function () {
    $skill = file_get_contents(base_path('.claude/skills/maintenance-advisor/SKILL.md'));

    $undocumented = collect(array_keys(app(TelemetrySnapshot::class)->build()))
        // Not sections, and nothing to read: the stamp on the payload and the
        // width of the ops window, both of which the file explains in prose.
        ->reject(fn (string $key): bool => in_array($key, ['generated_at', 'window_hours', 'season'], true))
        ->reject(fn (string $key): bool => str_contains($skill, "| `{$key}` |"))
        ->all();

    expect($undocumented)->toBe([]);
});

it('keeps the reading rules that stop a null being read as a zero', function () {
    /*
     * The rules themselves, pinned by the phrase that carries them. Every one
     * of these was written because reading the section without it produces a
     * confident wrong finding — a dead screen that nothing was counting, a
     * retention collapse in a cohort of three, a reminder that looks broken
     * because the number measuring it does not exist yet.
     *
     * Pinned as SUBSTRINGS, so the prose around them can be rewritten and only
     * a deleted rule fails.
     */
    $skill = file_get_contents(base_path('.claude/skills/maintenance-advisor/SKILL.md'));

    expect($skill)
        ->toContain('"too few to read"')
        ->toContain('`funnel_since` rule')
        ->toContain('Saturday to Saturday')
        ->toContain('routes.quiet')
        ->toContain('late_share')
        ->toContain('reminder_lift')
        ->toContain('views_24h')
        ->toContain('What NOT to file')
        // The one finding that may reach critical, and the one that is ops.
        ->toContain('critical')
        ->toContain('dead activity drain');
});
