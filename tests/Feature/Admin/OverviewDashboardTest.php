<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Analytics\ActivesStats;
use App\Filament\Widgets\Analytics\RouteTreemap;
use App\Filament\Widgets\Analytics\TodayPickem;
use App\Filament\Widgets\Analytics\TrafficArea;
use App\Models\ActivityEvent;
use App\Models\PageViewDaily;
use App\Models\User;
use App\Models\UserDay;
use App\Support\AnalyticsWindow;
use App\Support\Brand;
use App\Support\Cadence;
use Livewire\Livewire;

/*
 * Overview — the attention widgets, tested as CLASSES.
 *
 * Widget content is not in its page's HTML, so an assertion on /admin proves
 * only that the page rendered. And every ApexCharts widget here is deferred,
 * which means it renders a placeholder on the first pass exactly like a
 * #[Lazy] Livewire component: `Livewire::withoutLazyLoading()` applies to the
 * NEXT component only, so it is called before EVERY render and the deferred
 * ones are rendered twice.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    $this->travelTo('2026-09-05 18:00:00');
});

/** A deferred widget, rendered past its placeholder. */
function renderDeferred(string $widget, array $filters = []): object
{
    Livewire::withoutLazyLoading();
    Livewire::actingAs(test()->admin)->test($widget, ['pageFilters' => $filters]);

    Livewire::withoutLazyLoading();

    return Livewire::actingAs(test()->admin)->test($widget, ['pageFilters' => $filters]);
}

describe('the page', function () {
    it('renders for an admin at twelve columns', function () {
        $this->actingAs($this->admin)->get('/admin')->assertOk();
    });

    it('offers the range and the staff toggle, and defaults staff off', function () {
        /*
         * Staff OFF is the honest default at pilot scale: the founder's own
         * browsing is most of the traffic, so a chart that silently includes
         * it draws one person's afternoon as a trend.
         */
        $page = Livewire::actingAs($this->admin)->test(Dashboard::class);
        $filters = $page->instance()->filters;

        // A Select's state comes back as a STRING off the form, which is why
        // every widget resolves it through AnalyticsWindow rather than reading
        // the array — the window is what has to be 28, not the raw filter.
        expect(AnalyticsWindow::from($filters)->days)->toBe(28)
            ->and($filters['staff'] ?? null)->toBeFalse();
    });
});

describe('actives', function () {
    it('prints no data rather than 0% when there are too few to divide', function () {
        /*
         * A stat card reading "0%" is the most alarming possible rendering of
         * "we have not measured this yet". Below the floor the catalog returns
         * null and this prints the words.
         */
        Livewire::actingAs($this->admin)
            ->test(ActivesStats::class)
            ->assertOk()
            ->assertSee('no data')
            ->assertSee('Too few people to divide yet');
    });

    it('prints the rate once there are enough people, broken back from the null', function () {
        // Ten people present on both covered days: mean daily is 10, monthly
        // is 10, stickiness is 100%. Seeding is what flips the assertion
        // above, so neither passes for the wrong reason.
        foreach (User::factory()->count(10)->create() as $user) {
            foreach (['2026-09-04', '2026-09-05'] as $day) {
                UserDay::factory()->create(['user_id' => $user->id, 'day' => $day]);
            }
        }

        Livewire::actingAs($this->admin)
            ->test(ActivesStats::class)
            ->assertOk()
            ->assertSee('100%')
            ->assertDontSee('Too few people to divide yet');
    });
});

describe('the traffic chart', function () {
    it('says no data yet rather than drawing an empty axis', function () {
        $chart = renderDeferred(TrafficArea::class);

        expect($chart->instance()->options['noData']['text'])->toBe('No data yet');
    });

    it('starts the axis at since, never before the sensor was counting', function () {
        /*
         * A zero on this chart says "nobody read anything that day", which is
         * a real and alarming claim. For a day before the rollup started it is
         * fabricated — so the axis begins at `since` rather than at the
         * window's nominal start.
         */
        PageViewDaily::factory()->create(['day' => '2026-09-04', 'views' => 5]);

        $chart = renderDeferred(TrafficArea::class, ['window' => 28, 'staff' => false]);

        expect($chart->instance()->options['xaxis']['categories'])
            ->toBe(['2026-09-04', '2026-09-05']);
    });

    it('leaves staff off the chart until the toggle asks for them', function () {
        PageViewDaily::factory()->create([
            'day' => '2026-09-04', 'audience' => ActivityEvent::STAFF, 'views' => 400,
        ]);

        $without = renderDeferred(TrafficArea::class, ['window' => 28, 'staff' => false]);
        $with = renderDeferred(TrafficArea::class, ['window' => 28, 'staff' => true]);

        expect(collect($without->instance()->options['series'])->pluck('name')->all())
            ->toBe(['Members', 'Guests'])
            ->and(collect($with->instance()->options['series'])->pluck('name')->all())
            ->toBe(['Members', 'Guests', 'Staff']);
    });

    it('leaves the plugin\'s dark mode on, because it follows Filament\'s own class', function () {
        /*
         * The plan left this open: verify the charts follow Filament's `dark`
         * class rather than a theme of their own. They do — the plugin's chart
         * Blade emits `document.querySelector('html').matches('.dark')`, which
         * is exactly the class Filament toggles. So no `extraJsOptions()`
         * override is needed, and the only way to get this wrong now is to
         * turn the flag off.
         */
        expect(invade(new TrafficArea)->getDarkMode())->toBeTrue()
            ->and(invade(new RouteTreemap)->getDarkMode())->toBeTrue();
    });

    it('reads the accent at request time, so a rebrand needs no rebuild', function () {
        // The PicksTrendChart precedent: Brand is read per request, so an edit
        // on the App Branding page reaches the chart with no build step.
        $chart = renderDeferred(TrafficArea::class);

        expect($chart->instance()->options['colors'][0])->toBe(Brand::color('lager'));
    });
});

describe('the route treemap', function () {
    it('ranks screens through the catalog, so the panel and the payload agree', function () {
        PageViewDaily::factory()->create([
            'day' => '2026-09-04', 'route' => 'scoreboard',
            'audience' => ActivityEvent::MEMBER, 'views' => 9,
        ]);

        $chart = renderDeferred(RouteTreemap::class, ['window' => 28]);

        expect($chart->instance()->options['series'][0]['data'][0])
            ->toBe(['x' => 'scoreboard', 'y' => 9]);
    });

    it('says no data yet on an empty rollup', function () {
        $chart = renderDeferred(RouteTreemap::class);

        expect($chart->instance()->options['series'][0]['data'])->toBe([])
            ->and($chart->instance()->options['noData']['text'])->toBe('No data yet');
    });
});

describe("today's pick'em", function () {
    it('polls only on a Saturday, and not at all the rest of the week', function () {
        /*
         * A dashboard that polls all week is a query every minute for six days
         * to watch a number that cannot move. Null rather than a long
         * interval: "do not poll" is the honest state for settled numbers.
         */
        $this->travelTo(Cadence::currentSaturday()->setTime(14, 0));
        expect((new TodayPickem)->getPollingInterval())->toBe('60s');

        $this->travelTo(Cadence::currentSaturday()->addDays(3)->setTime(14, 0));
        expect((new TodayPickem)->getPollingInterval())->toBeNull();
    });

    it('says no data rather than 0% when there is no slate to measure', function () {
        Livewire::actingAs($this->admin)
            ->test(TodayPickem::class)
            ->assertOk()
            ->assertSee('no data')
            ->assertSee('No slate to measure yet');
    });
});
