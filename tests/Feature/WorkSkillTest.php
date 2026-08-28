<?php

use App\Console\Commands\IssueCommand;
use Illuminate\Support\Facades\Artisan;

/*
 * The `/work` skill is prose, and prose drifts.
 *
 * What can be tested about a markdown file is the one thing that actually goes
 * wrong: it tells a session to run commands, and those commands are code that
 * gets renamed. A skill naming an action `cfb:issue` no longer has is a session
 * that stops at step one — and nothing else in the suite would notice.
 *
 * It also tests the refusals from the other side. The skill must not hand a
 * session a runnable line that does something the board forbids, and "it says
 * not to" in the prose is not the same as "there is no such line to copy".
 */

/** The skill, as one string. */
function workSkill(): string
{
    $path = base_path('.claude/skills/work/SKILL.md');

    expect(file_exists($path))->toBeTrue();

    return (string) file_get_contents($path);
}

/** Only the fenced shell blocks — what a session actually copies and runs. */
function workSkillCommands(): string
{
    preg_match_all('/```bash\n(.*?)```/s', workSkill(), $matches);

    return implode("\n", $matches[1]);
}

describe('the skill activates', function () {
    it('carries the frontmatter that names it', function () {
        expect(workSkill())->toStartWith("---\nname: work\n")
            // Both ways in: a reference typed at it, and "the next ready issue".
            ->toContain('CFB-12')
            ->toContain('next ready issue');
    });
});

describe('every command it hands a session is real', function () {
    it('names only actions cfb:issue accepts', function () {
        // Read off the command's own signature, so a renamed action fails here
        // rather than in a session at 3am.
        $accepted = explode('|', (new IssueCommand)->getDefinition()->getArgument('action')->getDescription());

        preg_match_all('/cfb:issue\s+([a-z]+)/', workSkillCommands(), $matches);

        expect($matches[1])->not->toBeEmpty()
            ->each(fn ($action) => $action->toBeIn($accepted));
    });

    it('names only artisan commands that exist', function () {
        preg_match_all('/php artisan\s+([a-z:-]+)/', workSkillCommands(), $matches);
        $registered = array_keys(Artisan::all());

        expect(array_unique($matches[1]))->not->toBeEmpty()
            ->each(fn ($command) => $command->toBeIn($registered));
    });

    it('points the compare URL at the repository the config names', function () {
        // The fallback when `gh` is missing. A hardcoded host that drifts from
        // config sends a session to somebody else's repository.
        expect(workSkill())->toContain('https://'.config('cfb.repo_host').'/compare/main...');
    });
});

describe('it hands a session no line it must not run', function () {
    it('offers nothing that merges, closes or dismisses', function () {
        // Saying "do not merge" in prose is not the same as there being no
        // merge line to copy. This checks the copyable half.
        $runnable = workSkillCommands();

        foreach (['pr merge', 'push origin main', 'cfb:issue done', 'cfb:issue dismiss', 'OPS_TOKEN', '.env'] as $forbidden) {
            expect($runnable)->not->toContain($forbidden);
        }
    });

    it('says so in the prose as well', function () {
        expect(workSkill())
            ->toContain('The human merges.')
            ->toContain('Never work on `main`')
            ->toContain('Never rename the branch')
            ->toContain('bigger than the card');
    });

    it('never tells a session to run pint with --test', function () {
        // `--test` reports and exits non-zero instead of fixing, which reads as
        // a broken build rather than an unformatted file.
        expect(workSkillCommands())->not->toContain('pint --test');
    });
});
