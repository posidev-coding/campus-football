<?php

use App\Models\BrandSetting;
use App\Models\User;
use App\Support\Brand;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The brand is resolved in one place and rendered in five — the two layouts'
 * heads, the mark and lockup components, the manifest and favicon routes, and
 * the Filament panel. These hold the seams between them.
 */
describe('the head', function () {
    it('carries the icons and the manifest on both layouts', function (string $route) {
        $this->get($route)
            ->assertOk()
            ->assertSee('rel="icon"', escape: false)
            ->assertSee('type="image/svg+xml"', escape: false)
            ->assertSee('rel="apple-touch-icon"', escape: false)
            ->assertSee(route('manifest'), escape: false)
            ->assertSee('apple-mobile-web-app-title', escape: false)
            // Both spellings: iOS before 17.4 reads only the apple- one.
            ->assertSee('name="apple-mobile-web-app-capable"', escape: false)
            ->assertSee('name="mobile-web-app-capable"', escape: false);
    })->with([
        'app layout' => fn () => route('home'),
        'auth layout' => fn () => route('login'),
    ]);

    it('keeps chrome and content out from under the status bar in standalone', function () {
        /*
         * `black-translucent` + `viewport-fit=cover` means an installed app
         * draws under the Dynamic Island. Three things keep that safe, and each
         * regressed silently once: the header pads the top inset (the veil),
         * the content spacer counts the tab bar's bottom inset, and screen
         * chrome offsets by `--header-offset`, which restates the same inset.
         * In a browser tab every env() is 0, so no browser test can SEE the
         * failure — asserting the class strings is the layer a test can hold.
         */
        $html = $this->get(route('home'))->assertOk()->content();

        expect($html)->toContain('pt-[env(safe-area-inset-top)]')
            ->and($html)->toContain('env(safe-area-inset-bottom)');

        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain('--header-offset: calc(var(--spacing) * 14 + 1px + env(safe-area-inset-top));');
    });

    it('carries exactly one theme-color tag', function () {
        /*
         * The appearance sync does querySelector('meta[name=theme-color]') and
         * writes to whatever comes back FIRST. The brand's own head snippet
         * ships a media-scoped pair (one dark, one light) — pasting it in would
         * hand the sync the dark tag and silently undo the fix that stopped a
         * phone's address bar staying black in Light mode. There can only be
         * one, and nothing about the failure would point at the head.
         */
        $html = $this->get(route('home'))->assertOk()->content();

        expect(substr_count($html, 'name="theme-color"'))->toBe(1);
    });

    it('emits no color overrides while the brand is stock', function () {
        // A default install should carry no style block at all — the values are
        // already compiled into the stylesheet, and restating them is how two
        // sources of one truth start.
        expect(Brand::cssVariables())->toBe('');

        $this->get(route('home'))->assertOk()->assertDontSee('--color-brand-ink:', escape: false);
    });

    it('emits an override once a color differs, and only for that color', function () {
        BrandSetting::current()->update(['color_lager' => '#ff8200']);

        $css = Brand::cssVariables();

        expect($css)->toContain('--color-brand-lager:#ff8200')
            ->and($css)->not->toContain('--color-brand-ink');

        $this->get(route('home'))->assertOk()->assertSee('--color-brand-lager:#ff8200', escape: false);
    });
});

describe('the generated artefacts', function () {
    it('serves a manifest whose icons all resolve', function () {
        $manifest = $this->get(route('manifest'))->assertOk()->json();

        expect($manifest['name'])->toBe(Brand::name())
            ->and($manifest['display'])->toBe('standalone')
            ->and($manifest['icons'])->toHaveCount(3);

        foreach ($manifest['icons'] as $icon) {
            expect($icon['src'])->not->toBeNull();

            // Shipped icons are real files under public/, not a path we hope
            // exists — a manifest pointing at a 404 installs a blank icon on
            // someone's home screen and says nothing about it.
            expect(public_path(str($icon['src'])->after(url('/'))->ltrim('/')->before('?')->toString()))
                ->toBeReadableFile();
        }
    });

    it('serves a real favicon where a zero-byte file used to sit', function () {
        $response = $this->get('/favicon.ico')->assertOk();

        // The ICO magic: reserved 0, type 1, then the image count. Asserting
        // the header rather than just "non-empty" is what would catch the
        // packer emitting a PNG or a truncated directory.
        expect(bin2hex(substr($response->getContent(), 0, 6)))->toBe('000001000200');
    });

    it('rebuilds the ico from an uploaded favicon', function () {
        expect(strlen(Brand::ico()))->toBeGreaterThan(1000);
    });

    it('serves an iOS launch screen at the exact pixel size it declares', function () {
        [$w, $h, $dpr] = Brand::SPLASH[0];

        $png = $this->get("/brand/splash/{$w}x{$h}@{$dpr}.png")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->getContent();

        // iOS matches a startup image by exact device pixels; an off-by-one
        // splash is silently ignored and the launch flashes white instead.
        [$width, $height] = getimagesizefromstring($png);

        expect($width)->toBe($w * $dpr)
            ->and($height)->toBe($h * $dpr);
    });

    it('refuses a splash size it did not declare', function (string $spec) {
        $this->get("/brand/splash/{$spec}.png")->assertNotFound();
    })->with(['999x999@3', '440x956@9', '440x956@2', '0x0@2']);

    it('links a startup image per declared size on both layouts', function (string $route) {
        $html = $this->get($route)->assertOk()->content();

        expect(substr_count($html, 'apple-touch-startup-image'))->toBe(count(Brand::SPLASH));
    })->with([
        'app layout' => fn () => route('home'),
        'auth layout' => fn () => route('login'),
    ]);
});

describe('placement', function () {
    it('puts the mark beside the one heading Scores is allowed', function () {
        // Scores has no section strip, so its heading is not a repeat of one —
        // it is the app's only visible screen heading, and it keeps it.
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertSee('Scoreboard')
            ->assertSee('fill-brand-lager', escape: false);
    });

    it('drops the Account heading in favour of the brand', function () {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('account'))
            ->assertOk()
            ->content();

        // The tab that got you here already says "Account". The word survives
        // for a screen reader only, exactly as every League screen's does.
        expect($html)->toContain('<h1 class="sr-only">Account</h1>')
            ->and($html)->not->toContain('>Account</flux:heading>');
    });

    it('gives Home a brand nav that retires at sm, above a search bar that still pins', function () {
        $html = $this->get(route('home'))->assertOk()->content();

        // The nav scrolls away and the search bar is what sticks — two pinned
        // bars would put ~44px of permanent chrome back on a 390px screen.
        expect($html)->toContain('-mt-5 flex items-center justify-between gap-3 pt-3 sm:hidden')
            ->and($html)->toContain('sticky top-[env(safe-area-inset-top)] z-30 -mx-4 -mt-6');
    });

    it('renders the wordmark as real text, so it can be read and restyled', function () {
        // The vendor lockup is an SVG naming Archivo by family, and an SVG in
        // an <img> cannot see the page's fonts — it would render in system
        // sans wherever it was used.
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(Brand::WORDMARK['lead'])
            ->assertSee(Brand::WORDMARK['main']);
    });
});

describe('the resolver', function () {
    it('agrees with the stylesheet about the shipped palette', function () {
        // Two copies of three hex values: one in @theme for Tailwind to compile
        // against, one here for the runtime override to compare against. They
        // have to match, or "stock" means something different on each side and
        // the override block renders when nothing has changed.
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (Brand::COLORS as $key => $hex) {
            expect($css)->toContain("--color-brand-{$key}: {$hex};");
        }
    });

    it('falls back to the shipped file when an override is removed', function () {
        $setting = BrandSetting::current();

        $setting->update(['assets' => ['og-image' => 'brand/custom.png']]);
        expect(Brand::asset('og-image'))->toContain('/storage/brand/custom.png');

        $setting->update(['assets' => null]);
        expect(Brand::asset('og-image'))->toContain('/brand/og-image.png');
    });

    it('serves the new value on the SECOND read, not just the first', function () {
        /*
         * Brand memoizes in a static property on top of the cache, so a save
         * that only cleared the cache would still serve the old brand for the
         * rest of the request that made it — including the redirect the admin
         * page lands on, which is exactly where the change gets looked for. A
         * single-read test cannot see that; it passes on the value it just
         * wrote.
         */
        expect(Brand::color('lager'))->toBe(Brand::COLORS['lager']);

        BrandSetting::current()->update(['color_lager' => '#ff8200']);

        expect(Brand::color('lager'))->toBe('#ff8200')
            ->and(Brand::color('lager'))->toBe('#ff8200');
    });

    it('stamps an uploaded asset so a swap is not hidden by a cached favicon', function () {
        $setting = BrandSetting::current();
        $setting->update(['assets' => ['favicon-32' => 'brand/custom.png']]);

        expect(Brand::asset('favicon-32'))
            ->toContain('?v='.$setting->fresh()->updated_at->getTimestamp());
    });
});

describe('the admin panel', function () {
    it('wears the same brand the front end does', function () {
        $panel = Filament::getPanel('admin');

        expect($panel->getBrandName())->toBe(Brand::name())
            ->and($panel->getFontFamily())->toBe('Archivo Variable')
            ->and($panel->getFontUrl())->toContain('brand/archivo.css')
            ->and($panel->getFavicon())->toContain('favicon-32')
            // An Htmlable renders inline; a string would render as an <img>,
            // and an SVG in an <img> cannot see the page's fonts.
            ->and($panel->getBrandLogo())->toBeInstanceOf(Htmlable::class)
            ->and($panel->getDarkModeBrandLogo())->toBeInstanceOf(Htmlable::class);
    });

    it('takes its primary color from the brand', function () {
        BrandSetting::current()->update(['color_lager' => '#ff8200']);

        // Resolved per request through a closure, so an edit made in the panel
        // is live in the panel without a deploy.
        expect(Filament::getPanel('admin')->getColors()['primary'])->not->toBeEmpty();
        expect(Brand::color('lager'))->toBe('#ff8200');
    });

    it('ships a stylesheet for the font it names', function () {
        // LocalFontProvider emits a <link rel=stylesheet>, so this URL has to
        // be a CSS file — pointing it at the woff2 loads nothing and the panel
        // silently falls back to Inter.
        expect(public_path('brand/archivo.css'))->toBeReadableFile()
            ->and(file_get_contents(public_path('brand/archivo.css')))
            ->toContain("font-family: 'Archivo Variable'");
    });
});
