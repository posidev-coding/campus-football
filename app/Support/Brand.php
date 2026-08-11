<?php

namespace App\Support;

use App\Models\BrandSetting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The one thing anything asks about the brand.
 *
 * The shipped brand — the files in public/brand and the constants below — is
 * the default and is in git. The brand_settings row holds OVERRIDES, where a
 * null column means "use the shipped value". Every renderer goes through here:
 * the layouts' head, the mark and lockup components, the manifest and favicon
 * routes, and the Filament panel's own logo and primary color. One resolver is
 * what stops the tab icon, the home-screen icon and the sidebar from being
 * three different brands, the same discipline CoverageReport uses across the
 * Sync Health page and cfb:doctor.
 *
 * Everything cached here is a PLAIN ARRAY of scalars. An Eloquent model would
 * come back from Redis as __PHP_Incomplete_Class on the SECOND request, not
 * the first, which is the failure that hides through a whole test run.
 */
class Brand
{
    /** The shipped palette. Hex with the leading #, lowercased. */
    public const COLORS = [
        'ink' => '#0b0b0c',
        'cream' => '#f5f2ea',
        'lager' => '#e8a33c',
    ];

    /** The shipped wordmark: a tracked lead line over a heavy main line. */
    public const WORDMARK = [
        'lead' => 'CAMPUS',
        'main' => 'Football',
    ];

    /**
     * Asset key => the shipped file under public/, or null where the shipped
     * default is not a file at all.
     *
     * `mark-light` and `mark-dark` are null on purpose: the shipped mark is
     * the INLINE svg in the brand.mark component, which themes itself through
     * currentColor. An upload replaces it with an <img> pair, which is why
     * both variants have a slot.
     *
     * @var array<string, ?string>
     */
    public const SHIPPED = [
        'favicon-svg' => 'brand/favicon.svg',
        'favicon-16' => 'brand/favicon-16.png',
        'favicon-32' => 'brand/favicon-32.png',
        'favicon-48' => 'brand/favicon-48.png',
        'apple-touch' => 'brand/apple-touch-icon.png',
        'icon-192' => 'brand/icon-192.png',
        'icon-512' => 'brand/icon-512.png',
        'icon-maskable' => 'brand/icon-maskable-512.png',
        'og-image' => 'brand/og-image.png',
        'mark-light' => null,
        'mark-dark' => null,
    ];

    /**
     * The iOS launch-screen matrix: CSS points wide and tall, then the device
     * pixel ratio, portrait only — iPhones launch standalone web apps in
     * portrait whatever the device is doing, and the iPad sizes cover the
     * rotation that matters there. Kept to the X-class-and-later iPhones plus
     * the current iPad shapes rather than every historical size: an unmatched
     * device simply launches without a splash, which is what every device gets
     * today.
     *
     * @var list<array{int, int, int}>
     */
    public const SPLASH = [
        [440, 956, 3],   // iPhone 16/17 Pro Max
        [430, 932, 3],   // iPhone 15 Pro Max / 15 Plus / 14 Pro Max
        [428, 926, 3],   // iPhone 14 Plus / 13 Pro Max / 12 Pro Max
        [414, 896, 3],   // iPhone 11 Pro Max / XS Max
        [414, 896, 2],   // iPhone 11 / XR
        [402, 874, 3],   // iPhone 16/17 Pro
        [393, 852, 3],   // iPhone 14/15/16 Pro
        [390, 844, 3],   // iPhone 12/13/14
        [375, 812, 3],   // iPhone X / XS / 11 Pro / 12-13 mini
        [375, 667, 2],   // iPhone SE / 8
        [1024, 1366, 2], // iPad Pro 12.9" / 13"
        [834, 1194, 2],  // iPad Pro 11" / Air 11"
        [820, 1180, 2],  // iPad 10.9"
        [744, 1133, 2],  // iPad mini
    ];

    private const CACHE_KEY = 'brand:settings';

    private const TTL = 86400;

    /** @var ?array<string, mixed> */
    private static ?array $memo = null;

    /**
     * The stored overrides, as a plain array.
     *
     * Memoized on top of the cache because this is read a dozen times in one
     * request — every head tag, every lockup, every icon URL.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        return self::$memo ??= Cache::remember(self::CACHE_KEY, self::TTL, function (): array {
            $row = BrandSetting::query()->first();

            if ($row === null) {
                return [];
            }

            return array_filter([
                'name' => $row->name,
                'short_name' => $row->short_name,
                'tagline' => $row->tagline,
                'wordmark_lead' => $row->wordmark_lead,
                'wordmark_main' => $row->wordmark_main,
                'color_ink' => $row->color_ink,
                'color_cream' => $row->color_cream,
                'color_lager' => $row->color_lager,
                'assets' => $row->assets,
                'version' => $row->updated_at?->getTimestamp(),
            ], fn ($value) => $value !== null && $value !== '' && $value !== []);
        });
    }

    public static function name(): string
    {
        return self::settings()['name'] ?? config('app.name');
    }

    /**
     * The label under an iOS home-screen icon, where about 12 characters fit
     * before the launcher truncates it.
     */
    public static function shortName(): string
    {
        return self::settings()['short_name'] ?? 'Campus FB';
    }

    public static function tagline(): string
    {
        return self::settings()['tagline']
            ?? "Every game, every team, every player — and a pick'em your group will actually argue about.";
    }

    /**
     * @return array{lead: string, main: string}
     */
    public static function wordmark(): array
    {
        $settings = self::settings();

        return [
            'lead' => $settings['wordmark_lead'] ?? self::WORDMARK['lead'],
            'main' => $settings['wordmark_main'] ?? self::WORDMARK['main'],
        ];
    }

    public static function color(string $key): string
    {
        return self::settings()["color_{$key}"] ?? self::COLORS[$key];
    }

    /**
     * A URL for one of the icon slots, uploaded if there is one and shipped
     * otherwise.
     *
     * Carries a `?v=` stamped from the settings row's updated_at. A favicon is
     * cached hard by every browser there is, so without it a swap is invisible
     * until someone clears their cache — which reads as the upload having
     * failed.
     */
    public static function asset(string $key): ?string
    {
        $settings = self::settings();
        $path = $settings['assets'][$key] ?? null;

        if ($path !== null && $path !== '') {
            return self::disk()->url($path).'?v='.($settings['version'] ?? 0);
        }

        $shipped = self::SHIPPED[$key] ?? null;

        return $shipped === null ? null : asset($shipped);
    }

    /**
     * True when a slot has been overridden — the mark component asks, because
     * the shipped mark is inline SVG and an uploaded one is an <img>.
     */
    public static function hasCustom(string $key): bool
    {
        return filled(self::settings()['assets'][$key] ?? null);
    }

    /**
     * The web app manifest, generated rather than served as a static file:
     * its icons are editable, and two copies of the icon list is how the
     * home-screen icon ends up disagreeing with the tab icon.
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        $icons = [
            ['key' => 'icon-192', 'sizes' => '192x192', 'purpose' => 'any'],
            ['key' => 'icon-512', 'sizes' => '512x512', 'purpose' => 'any'],
            ['key' => 'icon-maskable', 'sizes' => '512x512', 'purpose' => 'maskable'],
        ];

        return [
            'name' => self::name(),
            'short_name' => self::shortName(),
            'description' => self::tagline(),
            'id' => '/',
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => self::color('ink'),
            'theme_color' => self::color('ink'),
            'icons' => collect($icons)
                ->map(fn (array $icon): array => [
                    'src' => self::asset($icon['key']),
                    'sizes' => $icon['sizes'],
                    'type' => 'image/png',
                    'purpose' => $icon['purpose'],
                ])
                ->all(),
        ];
    }

    /**
     * The `:root` block that retints the app at runtime, or '' when the brand
     * is stock.
     *
     * Tailwind 4 emits an `@theme` color as a custom property and compiles
     * `text-brand-ink` to `color: var(--color-brand-ink)`, so overriding the
     * property is enough to move every mark, stripe and wordmark on the page.
     * `@theme static` would inline the literal instead and make this a no-op.
     *
     * Empty when nothing differs, so a default install carries no style block
     * at all rather than one restating the values already compiled in.
     */
    public static function cssVariables(): string
    {
        $declarations = collect(self::COLORS)
            ->reject(fn (string $shipped, string $key): bool => self::color($key) === $shipped)
            ->map(fn (string $shipped, string $key): string => "--color-brand-{$key}:".self::color($key))
            ->values();

        return $declarations->isEmpty() ? '' : ':root{'.$declarations->implode(';').'}';
    }

    /**
     * favicon.ico, packed from the 16px and 32px favicons.
     *
     * There is no ICO encoder on this machine and none in PHP, but the format
     * barely needs one: a 6-byte header, a 16-byte directory entry per image,
     * then each image's bytes verbatim. PNG-in-ICO — writing the PNG straight
     * in rather than converting to a BMP — has been supported by every browser
     * since Vista and is what keeps this to a dozen lines.
     *
     * Returns null when neither favicon can be read, so the route can 404
     * rather than serve a zero-byte icon, which is what was there before.
     */
    public static function ico(): ?string
    {
        $images = collect(['favicon-16' => 16, 'favicon-32' => 32])
            ->map(fn (int $size, string $key): ?array => ($bytes = self::bytes($key)) === null
                ? null
                : ['size' => $size, 'bytes' => $bytes])
            ->filter()
            ->values();

        if ($images->isEmpty()) {
            return null;
        }

        /* ICONDIR: reserved 0, type 1 (icon), image count. */
        $header = pack('vvv', 0, 1, $images->count());

        /* Every entry's offset is past the header and the whole directory,
           so the payloads can only be laid out once the count is known. */
        $offset = 6 + (16 * $images->count());
        $directory = '';
        $payload = '';

        foreach ($images as $image) {
            $length = strlen($image['bytes']);

            /* width, height (0 means 256), palette, reserved; planes, bpp,
               byte length, offset. */
            $directory .= pack('CCCCvvVV', $image['size'], $image['size'], 0, 0, 1, 32, $length, $offset);
            $payload .= $image['bytes'];
            $offset += $length;
        }

        return $header.$directory.$payload;
    }

    /**
     * One iOS launch screen, composed on demand: the brand's ink, the app
     * icon centered at an icon's scale rather than a poster's.
     *
     * iOS ignores the manifest recipe Android builds its splash from — it
     * wants a pre-rendered PNG per device size, declared as
     * `apple-touch-startup-image` links. Composing them through this resolver
     * (the Brand::ico() discipline) is what keeps a rebrand's launch screen
     * in step with its icons; a shipped static set would be one more asset
     * list to forget.
     *
     * Cached under the settings row's version, so saving the App Branding
     * page starts a fresh set and the stale entries age out with the TTL.
     * Returns null when the icon cannot be read, so the route can 404.
     */
    public static function splash(int $width, int $height, int $dpr): ?string
    {
        $version = self::settings()['version'] ?? 0;

        return Cache::remember(
            "brand:splash:{$version}:{$width}x{$height}@{$dpr}",
            self::TTL,
            function () use ($width, $height, $dpr): ?string {
                if (($bytes = self::bytes('icon-512')) === null) {
                    return null;
                }

                if (($icon = imagecreatefromstring($bytes)) === false) {
                    return null;
                }

                $canvas = imagecreatetruecolor($width * $dpr, $height * $dpr);

                [$r, $g, $b] = sscanf(self::color('ink'), '#%02x%02x%02x');
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));

                $size = (int) round(min($width, $height) * $dpr * 0.28);

                imagecopyresampled(
                    $canvas,
                    $icon,
                    intdiv($width * $dpr - $size, 2),
                    intdiv($height * $dpr - $size, 2),
                    0,
                    0,
                    $size,
                    $size,
                    imagesx($icon),
                    imagesy($icon),
                );
                imagedestroy($icon);

                ob_start();
                imagepng($canvas);
                imagedestroy($canvas);

                return ob_get_clean() ?: null;
            },
        );
    }

    /**
     * The raw bytes behind an asset slot, uploaded or shipped.
     */
    public static function bytes(string $key): ?string
    {
        $path = self::settings()['assets'][$key] ?? null;

        if ($path !== null && $path !== '' && self::disk()->exists($path)) {
            return self::disk()->get($path);
        }

        $shipped = self::SHIPPED[$key] ?? null;

        /*
         * The shipped fallback is read from the LOCAL filesystem, never through
         * the upload disk — those files are in git and deploy with the app, so
         * a stock install's favicon must not depend on a bucket being reachable.
         */
        if ($shipped === null || ! is_file($file = public_path($shipped))) {
            return null;
        }

        return file_get_contents($file) ?: null;
    }

    /**
     * Where uploads live: the local `public` disk, or R2 on a deploy target
     * whose own filesystem does not survive a release.
     */
    private static function disk(): Filesystem
    {
        return Storage::disk(config('cfb.upload_disk'));
    }

    public static function flush(): void
    {
        self::$memo = null;

        Cache::forget(self::CACHE_KEY);
    }
}
