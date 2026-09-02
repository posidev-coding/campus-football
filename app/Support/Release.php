<?php

namespace App\Support;

/**
 * The release stamp.
 *
 * VERSION at the repository root is the one place the app's version is
 * written — `4.0.0-beta.1`, no `v` — and the git tag is always `v` plus that
 * file. The Release workflow keeps the two in step on every merge to main:
 * it bumps the file when its number is already tagged and tags the commit
 * as-is when a pull request chose the number. So nothing here asks git,
 * which the deployed image does not carry anyway.
 *
 * Null means NO STAMP — the file is missing, blank, or not a version — and
 * callers skip. Nothing here invents a number: a fallback like 0.0.0 would
 * print a version nobody chose on every Account screen and look deliberate.
 * Long form: docs/operations.md "Releases".
 */
class Release
{
    /**
     * Three numbers, then an optional pre-release and an optional build tag —
     * semver's own shape, without the `v` that belongs to the tag.
     */
    private const SHAPE = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';

    private static ?string $memo = null;

    /** Distinguishes "not read yet" from "read, and there is no stamp". */
    private static bool $resolved = false;

    /**
     * The version the app is running, or null without a readable stamp.
     *
     * Memoized: the layout asks on every page and Account asks again, and the
     * answer cannot change inside one process.
     */
    public static function version(): ?string
    {
        if (! self::$resolved) {
            self::$memo = self::read(config('cfb.version_file'));
            self::$resolved = true;
        }

        return self::$memo;
    }

    /**
     * The tag the stamp corresponds to — `v4.0.0-beta.1` — which is also the
     * form the screens print.
     */
    public static function tag(): ?string
    {
        $version = self::version();

        return $version === null ? null : 'v'.$version;
    }

    /**
     * Read one stamp file: trimmed, and only if it is a version.
     */
    public static function read(?string $path): ?string
    {
        if ($path === null || $path === '' || ! is_file($path)) {
            return null;
        }

        $contents = trim((string) file_get_contents($path));

        return preg_match(self::SHAPE, $contents) === 1 ? $contents : null;
    }

    public static function flush(): void
    {
        self::$memo = null;
        self::$resolved = false;
    }
}
