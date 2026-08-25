<?php

namespace App\Providers\Filament;

use App\Support\Brand;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            /*
             * THE PANEL'S OWN COMPILED TAILWIND, and the reason it exists.
             *
             * Filament's shipped stylesheet contains only the utilities its own
             * components use, so a Tailwind class written in an admin view has
             * no definition behind it and silently does nothing. The first Sync
             * Health page laid itself out with `grid grid-cols-2 gap-4` and
             * rendered as one unaligned column — which reads as bad design
             * rather than a missing stylesheet, and is why everything in the
             * panel until now was built from Filament's own widgets and tables.
             *
             * `resources/css/filament/admin/theme.css` scans `app/Filament` and
             * `resources/views/filament`, so classes written there are compiled.
             * This unblocks the Workbook board and any custom admin UI after it.
             *
             * Flux is still NOT available in here — its components need Flux's
             * own CSS and JS bundles, which the panel does not load. Plain
             * Tailwind and Filament's components are the vocabulary.
             */
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            /*
             * Every brand value is a CLOSURE, resolved per request, so an edit
             * on the App Branding page reaches the panel it was made in without
             * a deploy or a config cache clear.
             *
             * The logo is an Htmlable rather than a URL: Filament renders an
             * Htmlable inline and an <img> for a string, and an SVG loaded
             * through <img> cannot see the page's fonts — so a lockup file
             * would render its wordmark in system sans, which is exactly the
             * defect the brand's own README warns about.
             */
            ->brandName(fn (): string => Brand::name())
            ->brandLogo(fn () => view('filament.brand-lockup'))
            ->darkModeBrandLogo(fn () => view('filament.brand-lockup', ['dark' => true]))
            ->brandLogoHeight('2rem')
            ->favicon(fn (): ?string => Brand::asset('favicon-32'))
            /*
             * Archivo across the whole panel, not only the logo. LocalFontProvider
             * wants a STYLESHEET url, not a font file, which is why
             * public/brand/archivo.css exists — the front end's `@fonts` build
             * emits a hashed filename that would move on every build.
             */
            ->font('Archivo Variable', url: asset('brand/archivo.css'), provider: LocalFontProvider::class)
            /*
             * Stock Amber was a near miss for Lager. Driving it from the brand
             * means the admin's buttons move with the app's accent instead of
             * being a fourth place the color has to be kept in step.
             */
            ->colors(fn (): array => [
                'primary' => Color::hex(Brand::color('lager')),
            ])
            /*
             * The admin bell. One line, and it means a failed sync or a stalled
             * queue can reach somebody without a second delivery mechanism.
             *
             * The USER-facing equivalent is deliberately not built yet: an inbox
             * designed before anything writes to it is an inbox designed against
             * guesses. It arrives with gamification, which is what will fill it.
             */
            ->databaseNotifications()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
