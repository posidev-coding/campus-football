<?php

use App\Http\Middleware\RecordPageView;
use App\Models\PageViewDaily;
use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRouting\LivewirePageController;

/*
 * Which routes the quiet walk calls screens.
 *
 * AnalyticsCatalog::routes() walks the route table for screens with no views,
 * and asks RecordPageView::isScreenRoute() which routes are screens. A route
 * that cannot render one, such as a PNG, a 301 or a link only an email
 * carries, is then reported as a screen nobody opens, and somebody is
 * invited to delete it (CFB-121).
 */

beforeEach(function () {
    $this->travelTo('2026-09-05 18:00:00');

    // A month of rollup, so the 28-day window is covered and quiet is
    // published. With no page views at all, quiet is every screen.
    PageViewDaily::factory()->create(['day' => '2026-08-01', 'route' => 'home']);
});

function quietRoutes(): array
{
    return collect(app(AnalyticsCatalog::class)->routes(AnalyticsWindow::of(28))['quiet'])
        ->pluck('route')
        ->all();
}

it('does not call a route that cannot render a screen quiet', function (string $route) {
    expect(Route::has($route))->toBeTrue("{$route} is not a route any more")
        ->and(RecordPageView::isScreenRoute($route))->toBeFalse()
        ->and(quietRoutes())->not->toContain($route);
})->with([
    'invite card PNG' => 'brand.invite-card',
    'iOS splash PNG' => 'brand.splash',
    'legacy groups redirect' => 'picks.groups',
    'legacy group redirect' => 'picks.group',
    'legacy builder redirect' => 'picks.build',
    'legacy talk redirect' => 'pickem.talk',
    'email unsubscribe link' => 'newsletter.unsubscribe',
    'password reset link' => 'password.reset',
    'email verification link' => 'verification.verify',
]);

it('still calls a real screen with no views quiet', function () {
    // The skip list cannot be passing by emptying quiet: screens nobody
    // opened, signed in or out, are still there.
    expect(quietRoutes())->toContain('rankings')
        ->toContain('login')
        ->toContain('privacy');
});

it('calls a route a screen only when it renders as a Livewire page or a view', function () {
    /*
     * The architecture half. Every route the quiet walk can name must be a
     * Livewire page or a Route::view. A closure or controller added to a
     * page-view group next month fails here, rather than turning up as a
     * dead screen four weeks later. If it really renders a screen, allow it
     * here; if it does not, add it to RecordPageView::SKIP_ROUTES.
     */
    $renders = [LivewirePageController::class, ViewController::class];

    $strays = collect(quietRoutes())
        ->reject(function (string $name) use ($renders): bool {
            $controller = ltrim((string) Route::getRoutes()->getByName($name)->getControllerClass(), '\\');

            return in_array($controller, $renders, true);
        })
        ->values()
        ->all();

    expect(quietRoutes())->not->toBeEmpty()
        ->and($strays)->toBe([]);
});
