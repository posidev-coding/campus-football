<?php

use App\Enums\ActivityFeature;
use App\Enums\ViewportBucket;
use App\Filament\Pages\AudienceDashboard;
use App\Filament\Widgets\Analytics\AdoptionRadial;
use App\Filament\Widgets\Analytics\CohortRetentionHeatmap;
use App\Filament\Widgets\Analytics\DeviceMix;
use App\Filament\Widgets\Analytics\LifecycleFunnel;
use App\Filament\Widgets\Analytics\QuietScreens;
use App\Filament\Widgets\Analytics\TopTeamsBar;
use App\Filament\Widgets\Analytics\WeekHeat;
use App\Models\ActivityEvent;
use App\Models\PageViewDaily;
use App\Models\Season;
use App\Models\Team;
use App\Models\User;
use App\Models\UserDay;
use App\Models\UxEvent;
use App\Services\CfbCalendar;
use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use Livewire\Livewire;

/*
 * Audience — who is here, and whether they come back.
 *
 * Widgets are tested as CLASSES: widget content is not in its page's HTML.
 * The deferred ones (the two heatmaps) render a placeholder on the first pass,
 * so `Livewire::withoutLazyLoading()` is called before EVERY render and they
 * are rendered twice — it applies to the next component only.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    $this->travelTo('2026-09-05 18:00:00');
});

/** A deferred widget, rendered past its placeholder. */
function audienceWidget(string $widget, array $filters = []): object
{
    Livewire::withoutLazyLoading();
    Livewire::actingAs(test()->admin)->test($widget, ['pageFilters' => $filters]);

    Livewire::withoutLazyLoading();

    return Livewire::actingAs(test()->admin)->test($widget, ['pageFilters' => $filters]);
}

describe('the page', function () {
    it('renders for an admin and 403s for everybody else', function () {
        $this->actingAs($this->admin)->get('/admin/audience')->assertOk();

        $this->actingAs(User::factory()->create())->get('/admin/audience')->assertForbidden();
    });

    it('defaults to 28 days, members, and staff excluded', function () {
        /*
         * MEMBERS by default because "do people come back" is a question about
         * accounts — a guest has nothing to come back to. Staff off because at
         * pilot scale the founder's own browsing is most of the traffic.
         */
        Livewire::actingAs($this->admin)
            ->test(AudienceDashboard::class)
            ->assertSet('filters.range', '28d')
            ->assertSet('filters.audience', 'members')
            ->assertSet('filters.staff', false);
    });

    it('remembers a chosen range across a fresh mount', function () {
        /*
         * HasFiltersForm persists to the session, which is what makes the
         * range survive a reload — and what makes it a decision rather than a
         * default. Asserted through a SECOND mount rather than a browser
         * reload, because the value has to come back from the session and not
         * from the component that just set it.
         */
        Livewire::actingAs($this->admin)
            ->test(AudienceDashboard::class)
            ->set('filters.range', 'season')
            ->assertSet('filters.range', 'season');

        Livewire::actingAs($this->admin)
            ->test(AudienceDashboard::class)
            ->assertSet('filters.range', 'season');
    });

    it('resolves the season range to the season\'s own start date', function () {
        // The fourth range is the only one without a fixed width, and the year
        // comes from CfbCalendar rather than "the latest row in seasons" — a
        // season exists in the database months before it is played.
        $season = Season::factory()->create([
            'year' => app(CfbCalendar::class)->currentYear(),
            'start_date' => '2026-08-23',
        ]);

        $window = AnalyticsWindow::from(['range' => 'season']);

        expect($window->label)->toBe('season')
            ->and($window->fromDate())->toBe('2026-08-23')
            ->and($window->toDate())->toBe('2026-09-05');
    });

    it('falls back to the rolling default when no season row exists', function () {
        // Never an invented start date. With no season row the honest answer
        // is the default window, not January 1st.
        Season::query()->delete();

        expect(AnalyticsWindow::from(['range' => 'season'])->days)
            ->toBe(AnalyticsWindow::DEFAULT_DAYS);
    });
});

describe('the lifecycle funnel', function () {
    it('counts registrations from the funnel, so a pruned cohort still shows', function () {
        /*
         * THE CORRECTNESS OF THIS WHOLE CHART. Unverified accounts prune at
         * fourteen days, so counting `users` rows silently loses everybody who
         * registered and never came back — exactly the population a lifecycle
         * funnel exists to measure. It would make the drop-off vanish and
         * print the best number on the worst week.
         *
         * Nine people registered; the eight who never verified have since been
         * pruned, leaving one row in `users`. The first bar must still say 9.
         */
        UxEvent::create(['day' => '2026-09-02', 'signal' => 'onboarding_registered', 'count' => 9]);

        // The admin from beforeEach was also created inside the window, and
        // this assertion is about the GAP between the two sources — so the
        // `users` side is cleared to exactly the one survivor.
        User::query()->delete();

        User::factory()->create([
            'created_at' => '2026-09-02 10:00:00',
            'email_verified_at' => '2026-09-02 10:05:00',
        ]);

        $steps = app(AnalyticsCatalog::class)->lifecycle();

        expect($steps['registered'])->toBe(9)
            // ...and the later stages are honestly the survivors, which is why
            // the widget prints counts and never a percentage between them.
            ->and($steps['verified'])->toBe(1);
    });

    it('renders the six stages in order', function () {
        $chart = audienceWidget(LifecycleFunnel::class);

        expect($chart->instance()->options['xaxis']['categories'])
            ->toBe(['Registered', 'Verified', 'Onboarded', 'Reached Picks', 'Entered a slate', 'Installed']);
    });
});

describe('the retention heatmap', function () {
    it('leaves a cell blank rather than drawing 0% for a cohort of three', function () {
        /*
         * The most persuasive wrong chart an early product can draw itself. A
         * grid of honest-looking zeros invites the conclusion that nothing
         * retains, when the truth is that three people is not a rate. Apex
         * renders a null cell as empty, which is the reading we want, and the
         * row label carries n so the blank is legible as "too few".
         */
        $cohort = User::factory()->count(3)->create(['created_at' => '2026-08-25 09:00:00']);

        foreach ($cohort as $user) {
            UserDay::factory()->create(['user_id' => $user->id, 'day' => '2026-08-26']);
        }

        $chart = audienceWidget(CohortRetentionHeatmap::class);

        $row = collect($chart->instance()->options['series'])
            ->firstWhere('name', '2026-08-25 (n=3)');

        expect($row)->not->toBeNull()
            ->and($row['data'][0]['y'])->toBeNull();
    });

    it('draws a percentage once the cohort clears the floor', function () {
        // Twelve people, six of them back in week 0: 50%. Seeding is what
        // flips the assertion above, so neither passes for the wrong reason.
        $cohort = User::factory()->count(12)->create(['created_at' => '2026-08-25 09:00:00']);

        foreach ($cohort->take(6) as $user) {
            UserDay::factory()->create(['user_id' => $user->id, 'day' => '2026-08-26']);
        }

        $chart = audienceWidget(CohortRetentionHeatmap::class);

        $row = collect($chart->instance()->options['series'])
            ->firstWhere('name', '2026-08-25 (n=12)');

        expect($row['data'][0]['y'])->toBe(50.0);
    });
});

describe('adoption', function () {
    it('draws no bar at all below the floor, rather than a bar at zero', function () {
        // A radial bar at 0% is the most confident possible rendering of "we
        // cannot tell yet".
        $chart = audienceWidget(AdoptionRadial::class);

        expect($chart->instance()->options['series'])->toBe([])
            ->and(invade($chart->instance())->getSubheading())->toContain('Too few weekly actives');
    });

    it('draws the shares once there are enough people to divide by', function () {
        foreach (User::factory()->count(10)->create() as $i => $user) {
            UserDay::factory()->create([
                'user_id' => $user->id,
                'day' => '2026-09-03',
                'features' => $i < 5 ? ActivityFeature::Picked->value : 0,
            ]);
        }

        $chart = audienceWidget(AdoptionRadial::class);

        expect($chart->instance()->options['series'])->toContain(50.0)
            ->and(invade($chart->instance())->getSubheading())->toContain('Share of 10 weekly actives');
    });
});

describe('the device mix', function () {
    it('gives "not reported" its own slice rather than folding it into phone', function () {
        /*
         * The first HTML response of a session is sent before the client
         * cookie exists, so a real share of views have no width at all.
         * Bucketing those as Phone because most readers are on a phone would
         * invent the exact number this chart measures.
         */
        PageViewDaily::factory()->create([
            'day' => '2026-09-04', 'views' => 7,
            'viewport_bucket' => ViewportBucket::Unknown,
        ]);

        $chart = audienceWidget(DeviceMix::class);

        expect($chart->instance()->options['labels'][0])->toBe('Not reported')
            ->and($chart->instance()->options['series'][0])->toBe(7)
            // Gray, so it can never read as a real device class.
            ->and($chart->instance()->options['colors'][0])->toBe('#9ca3af');
    });
});

describe('the week heat', function () {
    it('lands a Sunday 01:00 UTC event on Saturday at 21, in league time', function () {
        /*
         * `CONVERT_TZ` does not know about DST the way the drain did when it
         * wrote the columns, so the hour has to be read off the stored value.
         * This is the fixture that catches a rewrite reaching for SQL.
         */
        ActivityEvent::factory()->count(2)->create(['occurred_at' => '2026-09-06 01:00:00']);

        $chart = audienceWidget(WeekHeat::class);

        $saturday = collect($chart->instance()->options['series'])->firstWhere('name', 'Sat');
        $cell = collect($saturday['data'])->firstWhere('x', '21');

        expect($cell['y'])->toBe(2);
    });
});

describe('the quiet screens', function () {
    it('refuses to answer until the rollup covers the window', function () {
        /*
         * The finding this table invites is "delete the door". A screen looks
         * dead for exactly the reason a new funnel signal reads zero, so filed
         * off a two-day-old rollup it is the funnel_since bug with a bigger
         * blast radius.
         */
        PageViewDaily::factory()->create(['day' => '2026-09-04', 'route' => 'home']);

        Livewire::actingAs($this->admin)
            ->test(QuietScreens::class)
            ->assertOk()
            ->assertSee('Not enough history yet')
            ->assertSee('Withheld until the rollup covers the window');
    });

    it('lists a screen with no rows at all, and prints since', function () {
        // Absence cannot be read out of a table of what happened, so the
        // catalog walks the route table — over routes the sensor runs on.
        PageViewDaily::factory()->create(['day' => '2026-08-01', 'route' => 'home']);

        Livewire::actingAs($this->admin)
            ->test(QuietScreens::class)
            ->assertOk()
            ->assertSee('scoreboard')
            // 08-09, not the rollup's own 08-01: `since` is the LATER of the
            // two, because a window cannot report days it does not cover. The
            // rollup reaching further back than the window is exactly what
            // makes this window trustworthy.
            ->assertSee('since 2026-08-09')
            // The description, not the absence of the empty state: Filament
            // renders an empty-state heading into the DOM whether or not it
            // is showing, so assertDontSee on it passes for the wrong reason.
            ->assertSee('Under '.AnalyticsCatalog::QUIET_VIEWS.' member views in 28 days');
    });
});

describe('the converted charts', function () {
    it('carries each school\'s own color and a gray for one ESPN gave us none', function () {
        // Behavior carried over from the Chart.js widget rather than
        // rewritten: a fabricated brand color on a real school is worse than
        // an honest gray.
        $colored = Team::factory()->create(['abbreviation' => 'TENN', 'color' => 'FF8200']);
        $none = Team::factory()->create(['abbreviation' => 'GRAY', 'color' => null]);

        $colored->followers()->attach(User::factory()->count(2)->create()->pluck('id'), ['position' => 1]);
        $none->followers()->attach(User::factory()->create()->id, ['position' => 1]);

        $chart = audienceWidget(TopTeamsBar::class);

        expect($chart->instance()->options['xaxis']['categories'])->toBe(['TENN', 'GRAY'])
            ->and($chart->instance()->options['colors'])->toBe(['#FF8200', '#9ca3af']);
    });

    it('leaves a team nobody follows off the chart entirely', function () {
        Team::factory()->create(['abbreviation' => 'NOBODY']);

        expect(audienceWidget(TopTeamsBar::class)->instance()->options['xaxis']['categories'])->toBe([]);
    });
});
