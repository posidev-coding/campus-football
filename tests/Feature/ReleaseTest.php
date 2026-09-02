<?php

use App\Models\User;
use App\Support\Release;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

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
});
