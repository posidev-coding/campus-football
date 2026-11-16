<?php

use App\Models\User;
use App\Support\Release;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The release stamp: VERSION at the repository root, read by Release, printed
 * on Account and in the desktop avatar menu, and bumped and tagged by the
 * Release workflow on every merge to main.
 */
function writeReleaseStamp(?string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'cfb-version-');

    if ($contents === null) {
        unlink($path);
    } else {
        file_put_contents($path, $contents);
    }

    config()->set('cfb.version_file', $path);
    Release::flush();

    return $path;
}

function runNextVersion(string $current): ProcessResult
{
    return Process::run(['bash', base_path('.github/scripts/next-version.sh'), $current]);
}

describe('the stamp', function () {
    it('is a semantic version the tag is built from', function () {
        // The file the repository actually carries: three numbers, an optional
        // pre-release, no `v`. The `v` is the tag's, and only the tag's.
        $version = Release::version();

        expect($version)->not->toBeNull()
            ->and($version)->toMatch('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/')
            ->and($version)->not->toStartWith('v')
            ->and(Release::tag())->toBe('v'.$version);
    });

    it('trims the file, since an editor leaves a newline', function () {
        writeReleaseStamp("  4.2.1\n\n");

        expect(Release::version())->toBe('4.2.1')
            ->and(Release::tag())->toBe('v4.2.1');
    });

    it('is null, never a default, without a readable stamp', function (?string $contents) {
        /*
         * The rule this app broke three times over: a missing value is null
         * and the caller skips. A stamp that fell back to 0.0.0 or "dev"
         * would print a version nobody chose on every account screen.
         */
        writeReleaseStamp($contents);

        expect(Release::version())->toBeNull()
            ->and(Release::tag())->toBeNull();
    })->with([
        'no file' => null,
        'blank' => '',
        'whitespace' => "\n   \n",
        'a word' => 'latest',
        'a v prefix' => 'v4.0.0',
        'two parts' => '4.0',
    ]);

    it('reads once and serves the memo until flushed', function () {
        $path = writeReleaseStamp('4.0.0-beta.7');

        expect(Release::version())->toBe('4.0.0-beta.7');

        file_put_contents($path, '9.9.9');

        expect(Release::version())->toBe('4.0.0-beta.7');

        Release::flush();

        expect(Release::version())->toBe('9.9.9');
    });
});

describe('where it shows', function () {
    it('prints the tag on Account and in the desktop avatar menu', function () {
        writeReleaseStamp('4.0.0-beta.3');

        $html = $this->actingAs(User::factory()->create())
            ->get(route('account'))
            ->assertOk()
            ->content();

        // Twice: the screen's own footer, and the header's avatar menu. The
        // header is hidden below `sm`, so the footer is the phone's copy.
        expect(substr_count($html, 'data-release'))->toBe(2)
            ->and($html)->toContain('v4.0.0-beta.3');
    });

    it('prints nothing at all without a stamp', function () {
        writeReleaseStamp(null);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('account'))
            ->assertOk()
            ->content();

        expect($html)->not->toContain('data-release');
    });
});

describe('the bump', function () {
    it('walks a pre-release by its last number, and a release by its patch', function (string $current, string $next) {
        $result = runNextVersion($current);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and(trim($result->output()))->toBe($next);
    })->with([
        'beta' => ['4.0.0-beta.1', '4.0.0-beta.2'],
        'beta into two digits' => ['4.0.0-beta.9', '4.0.0-beta.10'],
        'rc' => ['4.0.0-rc.1', '4.0.0-rc.2'],
        'release' => ['4.0.0', '4.0.1'],
        'release into two digits' => ['4.1.9', '4.1.10'],
    ]);

    it('refuses to guess at anything it cannot read', function (string $current) {
        // A failed run is a red check on the merge; a guessed number is a tag
        // nobody can trust. Nothing on stdout either, so a caller that
        // forgot to check the exit code still has nothing to tag with.
        $result = runNextVersion($current);

        expect($result->failed())->toBeTrue()
            ->and(trim($result->output()))->toBe('');
    })->with([
        'empty' => '',
        'a v prefix' => 'v4.0.0',
        'a pre-release with no number' => '4.0.0-beta',
        'two parts' => '4.0',
        'a word' => 'latest',
    ]);

    it('can take one step from the stamp the repository carries', function () {
        // Whatever VERSION says today, the next merge has to be able to bump
        // it — or the first automatic release after this one fails.
        $result = runNextVersion(Release::version());

        expect($result->successful())->toBeTrue($result->errorOutput());
    });
});

/**
 * Git, isolated from whoever runs the suite: no global or system config (a
 * signing hook or a default-branch setting would change what is tested), and
 * a fixed identity, because a tag and a rebase both need a committer.
 */
function releaseGit(string $cwd, string ...$args): ProcessResult
{
    return Process::path($cwd)->env([
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_AUTHOR_NAME' => 'Release Test',
        'GIT_AUTHOR_EMAIL' => 'release@example.test',
        'GIT_COMMITTER_NAME' => 'Release Test',
        'GIT_COMMITTER_EMAIL' => 'release@example.test',
    ])->run(['git', ...$args])->throw();
}

/**
 * A throwaway origin with main at 4.0.0-beta.3 (tagged), plus two clones of
 * it: `merger` stands in for GitHub landing a pull request, and `runner` is
 * the Release workflow's checkout with the bot's bump to beta.4 already
 * committed — the state push-release.sh is called in.
 *
 * @return array{origin: string, merger: string, runner: string, output: string}
 */
function releaseSandbox(): array
{
    $root = sys_get_temp_dir().'/cfb-release-'.Str::random(12);
    File::makeDirectory($root);
    test()->releaseRoot = $root;

    $sandbox = [
        'origin' => "{$root}/origin.git",
        'merger' => "{$root}/merger",
        'runner' => "{$root}/runner",
        'output' => "{$root}/github-output",
    ];

    releaseGit($root, 'init', '--quiet', '--bare', '--initial-branch=main', $sandbox['origin']);
    releaseGit($root, 'clone', '--quiet', $sandbox['origin'], $sandbox['merger']);

    File::put("{$sandbox['merger']}/VERSION", "4.0.0-beta.3\n");
    File::put("{$sandbox['merger']}/README.md", "Campus Football\n");
    releaseGit($sandbox['merger'], 'add', '.');
    releaseGit($sandbox['merger'], 'commit', '--quiet', '-m', 'Release v4.0.0-beta.3');
    releaseGit($sandbox['merger'], 'tag', '-a', 'v4.0.0-beta.3', '-m', 'Release v4.0.0-beta.3');
    releaseGit($sandbox['merger'], 'push', '--quiet', 'origin', 'HEAD:refs/heads/main', 'refs/tags/v4.0.0-beta.3');

    releaseGit($root, 'clone', '--quiet', $sandbox['origin'], $sandbox['runner']);
    File::put($sandbox['output'], '');

    return $sandbox;
}

/** What the workflow's bump does before it calls the script. */
function commitReleaseBump(string $runner, string $version): void
{
    File::put("{$runner}/VERSION", "{$version}\n");
    releaseGit($runner, 'add', 'VERSION');
    releaseGit($runner, 'commit', '--quiet', '-m', "Release v{$version}");
}

/** A pull request merging to main while the release run is in flight. */
function landMerge(string $merger, string $file, string $contents, string $message): void
{
    releaseGit($merger, 'pull', '--quiet', '--ff-only', 'origin', 'main');
    File::put("{$merger}/{$file}", $contents);
    releaseGit($merger, 'add', $file);
    releaseGit($merger, 'commit', '--quiet', '-m', $message);
    releaseGit($merger, 'push', '--quiet', 'origin', 'HEAD:refs/heads/main');
}

/** @param  array{origin: string, merger: string, runner: string, output: string}  $sandbox */
function runPushRelease(array $sandbox, string $version): ProcessResult
{
    return Process::path($sandbox['runner'])->env([
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_AUTHOR_NAME' => 'github-actions[bot]',
        'GIT_AUTHOR_EMAIL' => 'bot@example.test',
        'GIT_COMMITTER_NAME' => 'github-actions[bot]',
        'GIT_COMMITTER_EMAIL' => 'bot@example.test',
        'GITHUB_OUTPUT' => $sandbox['output'],
    ])->run(['bash', base_path('.github/scripts/push-release.sh'), $version]);
}

/** The commit a ref names on the origin, or null when it does not exist there. */
function originRef(string $origin, string $ref): ?string
{
    $result = Process::path($origin)->run(['git', 'rev-parse', '--verify', '--quiet', "{$ref}^{commit}"]);

    return $result->successful() ? trim($result->output()) : null;
}

/** The file at main's tip on the origin. */
function originFile(string $origin, string $path): string
{
    return trim(releaseGit($origin, 'show', "main:{$path}")->output());
}

describe('the push', function () {
    afterEach(function () {
        if (isset($this->releaseRoot)) {
            File::deleteDirectory($this->releaseRoot);
        }
    });

    it('pushes the bump and tags it when main has not moved', function () {
        $sandbox = releaseSandbox();
        commitReleaseBump($sandbox['runner'], '4.0.0-beta.4');

        $result = runPushRelease($sandbox, '4.0.0-beta.4');

        expect($result->exitCode())->toBe(0, $result->errorOutput())
            ->and(originFile($sandbox['origin'], 'VERSION'))->toBe('4.0.0-beta.4')
            ->and(originRef($sandbox['origin'], 'refs/tags/v4.0.0-beta.4'))->toBe(originRef($sandbox['origin'], 'refs/heads/main'))
            ->and(File::get($sandbox['output']))->toBe("released=true\n");
    });

    it('rebases onto a merge that landed mid-run, then pushes and tags the rebased commit', function () {
        /*
         * The 2026-11-16 failure: the run checked out main, a pull request
         * merged, and the bare push was rejected. The merge must survive on
         * main, the Release commit must sit on top of it, and the tag must
         * name that rebased commit — not the one the run made.
         */
        $sandbox = releaseSandbox();
        commitReleaseBump($sandbox['runner'], '4.0.0-beta.4');
        $before = trim(releaseGit($sandbox['runner'], 'rev-parse', 'HEAD')->output());

        landMerge($sandbox['merger'], 'README.md', "Campus Football, louder\n", 'Merge a pull request mid-run');
        $merge = originRef($sandbox['origin'], 'refs/heads/main');

        $result = runPushRelease($sandbox, '4.0.0-beta.4');
        $main = originRef($sandbox['origin'], 'refs/heads/main');

        expect($result->exitCode())->toBe(0, $result->errorOutput())
            ->and($result->output())->toContain('rebasing the Release commit')
            ->and($main)->not->toBe($before)
            ->and(originRef($sandbox['origin'], 'refs/heads/main~1'))->toBe($merge)
            ->and(originFile($sandbox['origin'], 'VERSION'))->toBe('4.0.0-beta.4')
            ->and(originFile($sandbox['origin'], 'README.md'))->toBe('Campus Football, louder')
            ->and(originRef($sandbox['origin'], 'refs/tags/v4.0.0-beta.4'))->toBe($main)
            ->and(File::get($sandbox['output']))->toBe("released=true\n");
    });

    it('tags nothing and exits 0 when a later merge chose its own number', function () {
        // A pull request edited VERSION after the run read it. Its number wins
        // on the next run; this one must not tag a number main does not carry.
        $sandbox = releaseSandbox();
        commitReleaseBump($sandbox['runner'], '4.0.0-beta.4');

        landMerge($sandbox['merger'], 'VERSION', "4.1.0\n", 'Choose 4.1.0');
        $merge = originRef($sandbox['origin'], 'refs/heads/main');

        $result = runPushRelease($sandbox, '4.0.0-beta.4');

        expect($result->exitCode())->toBe(0, $result->errorOutput())
            ->and($result->output())->toContain('a later merge changed VERSION')
            ->and(originRef($sandbox['origin'], 'refs/tags/v4.0.0-beta.4'))->toBeNull()
            ->and(originRef($sandbox['origin'], 'refs/heads/main'))->toBe($merge)
            ->and(File::get($sandbox['output']))->toBe("released=false\n")
            // Aborted, not left mid-rebase in the runner's clone.
            ->and(File::exists("{$sandbox['runner']}/.git/rebase-merge"))->toBeFalse();
    });

    it('tags nothing and exits 0 when main already carries the same bump', function () {
        // An earlier run released this exact number on top of the merge this
        // run was started for; its bump is already upstream.
        $sandbox = releaseSandbox();
        commitReleaseBump($sandbox['runner'], '4.0.0-beta.4');

        // A different message, because an identical commit made in the same
        // second hashes identically and would never reach the rebase at all;
        // a real earlier run's commit differs by its timestamp.
        landMerge($sandbox['merger'], 'VERSION', "4.0.0-beta.4\n", 'Release v4.0.0-beta.4 (an earlier run)');
        $main = originRef($sandbox['origin'], 'refs/heads/main');

        $result = runPushRelease($sandbox, '4.0.0-beta.4');

        expect($result->exitCode())->toBe(0, $result->errorOutput())
            ->and($result->output())->toContain('already carries this bump')
            ->and(originRef($sandbox['origin'], 'refs/heads/main'))->toBe($main)
            ->and(originRef($sandbox['origin'], 'refs/tags/v4.0.0-beta.4'))->toBeNull()
            ->and(File::get($sandbox['output']))->toBe("released=false\n");
    });

    it('only tags when the pull request chose the number and there is no bump to push', function () {
        $sandbox = releaseSandbox();
        landMerge($sandbox['merger'], 'VERSION', "4.1.0\n", 'Choose 4.1.0');
        releaseGit($sandbox['runner'], 'pull', '--quiet', '--ff-only', 'origin', 'main');
        $main = originRef($sandbox['origin'], 'refs/heads/main');

        $result = runPushRelease($sandbox, '4.1.0');

        expect($result->exitCode())->toBe(0, $result->errorOutput())
            ->and(originRef($sandbox['origin'], 'refs/heads/main'))->toBe($main)
            ->and(originRef($sandbox['origin'], 'refs/tags/v4.1.0'))->toBe($main)
            ->and(File::get($sandbox['output']))->toBe("released=true\n");
    });

    it('never moves a tag that is already pushed', function () {
        // The number is somehow tagged already. Failing red is right; moving a
        // tag somebody may have deployed is not.
        $sandbox = releaseSandbox();
        releaseGit($sandbox['merger'], 'tag', '-a', 'v4.0.0-beta.4', '-m', 'Already here');
        releaseGit($sandbox['merger'], 'push', '--quiet', 'origin', 'refs/tags/v4.0.0-beta.4');
        $tagged = originRef($sandbox['origin'], 'refs/tags/v4.0.0-beta.4');

        commitReleaseBump($sandbox['runner'], '4.0.0-beta.4');

        $result = runPushRelease($sandbox, '4.0.0-beta.4');

        expect($result->successful())->toBeFalse()
            ->and(originRef($sandbox['origin'], 'refs/tags/v4.0.0-beta.4'))->toBe($tagged)
            ->and(File::get($sandbox['output']))->not->toContain('released=true');
    });

    it('refuses a version VERSION does not say, and anything but one commit to push', function () {
        $sandbox = releaseSandbox();
        commitReleaseBump($sandbox['runner'], '4.0.0-beta.4');

        expect(runPushRelease($sandbox, '4.0.0-beta.9')->successful())->toBeFalse();

        commitReleaseBump($sandbox['runner'], '4.0.0-beta.5');

        expect(runPushRelease($sandbox, '4.0.0-beta.5')->successful())->toBeFalse()
            ->and(originFile($sandbox['origin'], 'VERSION'))->toBe('4.0.0-beta.3');
    });
});

describe('the workflow', function () {
    it('keeps the load-bearing lines', function () {
        /*
         * A source pin, because nothing here can run Actions. The token is
         * read-only by repository default, so the permissions block is what
         * lets the bot tag; the concurrency group is what stops two merges
         * racing for one number; the script is the only place a number is
         * computed; and the deploy hook must name the tagged commit.
         */
        $yaml = file_get_contents(base_path('.github/workflows/release.yml'));

        expect($yaml)->toContain('branches: [main]')
            ->and($yaml)->toContain('contents: write')
            ->and($yaml)->toContain('group: release')
            ->and($yaml)->toContain('fetch-depth: 0')
            ->and($yaml)->toContain('.github/scripts/next-version.sh')
            ->and($yaml)->toContain('--generate-notes')
            ->and($yaml)->toContain('commit_hash=$(git rev-parse HEAD)');
    });

    it('pushes and tags only through push-release.sh, and publishes only what it tagged', function () {
        /*
         * A bare `git push origin HEAD:main` is the 2026-11-16 failure: a merge
         * landing mid-run rejected it and the run died before tagging. The
         * script owns the push and the tag, and the notes and the deploy must
         * not run when it tagged nothing — `gh release create` on a missing
         * tag would CREATE one at main's tip, a number main does not carry.
         */
        $yaml = file_get_contents(base_path('.github/workflows/release.yml'));

        expect($yaml)->toContain('.github/scripts/push-release.sh "$VERSION_NEXT"')
            ->and($yaml)->not->toContain('git push origin HEAD:main')
            ->and($yaml)->not->toContain('git tag -a')
            ->and(substr_count($yaml, "steps.release.outputs.released == 'true'"))->toBe(2)
            ->and(is_executable(base_path('.github/scripts/push-release.sh')))->toBeTrue();
    });
});
