<?php

use App\Actions\RecordActivity;
use App\Filament\Pages\HealthDashboard;
use App\Filament\Widgets\Analytics\AdvisorLedger;
use App\Filament\Widgets\Analytics\ErrorRateByRoute;
use App\Filament\Widgets\Analytics\IngestBuffers;
use App\Filament\Widgets\Analytics\OpsChecks;
use App\Filament\Widgets\Analytics\PerformanceTop;
use App\Jobs\FetchGameSummary;
use App\Models\ActivityEvent;
use App\Models\ClientError;
use App\Models\FeedRun;
use App\Models\Group;
use App\Models\User;
use App\Support\OpsReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;

/*
 * Health — the same rows `cfb:telemetry` prints, for somebody at a keyboard.
 *
 * Every widget here reads the SAME support class the ops snapshot does. That
 * is the point of the page and the reason each test below asserts through the
 * widget class rather than the page HTML: if these could drift from the
 * payload, the panel would be a second opinion rather than a second skin.
 */

beforeEach(function () {
    Redis::connection('pulse')->flushdb();

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    $this->travelTo('2026-09-05 18:00:00');
});

describe('the page', function () {
    it('lives at /admin/health and 403s for everybody else', function () {
        $this->actingAs($this->admin)->get('/admin/health')->assertOk();

        $this->actingAs(User::factory()->create())->get('/admin/health')->assertForbidden();
    });

    it('carries no range filter, because every row on it is already scoped', function () {
        /*
         * A range selector here would invite reading a twenty-four-hour check
         * over ninety days — which is not the same check with a wider window,
         * it is a different question wearing the same label.
         */
        expect(method_exists(HealthDashboard::class, 'filtersForm'))->toBeFalse();
    });

    it('lists its five widgets explicitly', function () {
        expect(Livewire::actingAs($this->admin)->test(HealthDashboard::class)->instance()->getWidgets())
            ->toBe([
                OpsChecks::class,
                IngestBuffers::class,
                ErrorRateByRoute::class,
                PerformanceTop::class,
                AdvisorLedger::class,
            ]);
    });
});

describe('the ops checks', function () {
    it('prints the remedy verbatim rather than prefixing a command onto it', function () {
        /*
         * The bug this pins shut, found in a browser rather than by a test:
         * this column was copied from DataCoverage, which prefixes "php
         * artisan" because CoverageReport's remedies are BARE COMMANDS.
         * OpsReport's are finished sentences, so the panel rendered
         * "php artisan php artisan pulse:work" on one row — and on another,
         * where the remedy is prose rather than a command, an instruction that
         * told an admin to run a sentence.
         *
         * Copying a column is not the same as copying its contract.
         */
        // A green environment has no remedies to render, so the failure that
        // produces one is seeded rather than hoped for.
        foreach (range(1, 12) as $i) {
            FeedRun::jobFailed(FetchGameSummary::class, 'boom');
        }

        $remedies = collect(app(OpsReport::class)->checks())->pluck('remedy')->filter();

        expect($remedies)->not->toBeEmpty();

        $page = Livewire::actingAs($this->admin)->test(OpsChecks::class)->assertOk();

        foreach ($remedies as $remedy) {
            $page->assertSee($remedy)->assertDontSee("php artisan {$remedy}");
        }
    });

    it('renders the same rows OpsReport hands the snapshot', function () {
        // One implementation, three surfaces. A row here that the terminal
        // does not print is a panel telling a different story than the
        // advisor is reading.
        Livewire::actingAs($this->admin)
            ->test(OpsChecks::class)
            ->assertOk()
            ->assertSee('Application');
    });
});

describe('the ingest buffers', function () {
    it('says unreachable rather than zero when Redis cannot be asked', function () {
        /*
         * THE MOST IMPORTANT NULL ON THIS PAGE. An unreachable Redis returning
         * a confident 0 reads as "the drain is perfectly caught up", which is
         * the exact opposite of what it means — and a stalled drain is already
         * indistinguishable from a quiet week on every other widget.
         *
         * The outage is injected at RecordActivity rather than at Redis: the
         * action already swallows every Throwable and answers null, so null is
         * its contract for "could not ask", and this asserts the widget honors
         * it. Simulating the socket instead would test phpredis.
         */
        $this->instance(RecordActivity::class, new class extends RecordActivity
        {
            public function pending(): ?int
            {
                return null;
            }
        });

        Livewire::actingAs($this->admin)
            ->test(IngestBuffers::class)
            ->assertOk()
            ->assertSee('unreachable')
            ->assertSee('Could not reach the telemetry Redis database');
    });

    it('says the drain is keeping up on an empty buffer', function () {
        // Broken back from the assertion above: a healthy Redis reports 0 and
        // says so in words, so "unreachable" cannot be passing by accident.
        Livewire::actingAs($this->admin)
            ->test(IngestBuffers::class)
            ->assertOk()
            ->assertSee('Empty')
            ->assertDontSee('unreachable');
    });
});

describe('the error rate', function () {
    it('withholds a rate under the view floor and still shows the counts', function () {
        /*
         * A percentage over nine views moves eleven points per error and would
         * put a rounding artifact at the top of a table sorted by severity. A
         * bug on a screen ten people opened is still a bug — its evidence is
         * the report count, not a percentage.
         */
        ClientError::create([
            'fingerprint' => str_repeat('a', 40), 'kind' => 'error',
            'message' => 'Cannot read properties of undefined',
            'reports' => 3, 'path' => '/scoreboard',
        ]);

        ActivityEvent::factory()->count(3)->create([
            'route' => 'scoreboard', 'occurred_at' => now()->subHour(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(ErrorRateByRoute::class)
            ->assertOk()
            ->assertSee('scoreboard')
            ->assertSee('too few');
    });

    it('draws the rate once the screen has enough traffic to divide by', function () {
        ClientError::create([
            'fingerprint' => str_repeat('b', 40), 'kind' => 'error',
            'message' => 'boom', 'reports' => 5, 'path' => '/scoreboard',
        ]);

        ActivityEvent::factory()->count(ErrorRateByRoute::MIN_VIEWS)->create([
            'route' => 'scoreboard', 'occurred_at' => now()->subHour(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(ErrorRateByRoute::class)
            ->assertOk()
            ->assertSee('10%')
            ->assertDontSee('too few');
    });

    it('resolves a group path to a route name rather than echoing the id', function () {
        // A path carries ids, and an invite code or a signed link rendered
        // into an admin table is the thing the sensor design refuses.
        $group = Group::factory()->create();

        ClientError::create([
            'fingerprint' => str_repeat('c', 40), 'kind' => 'error',
            'message' => 'boom', 'reports' => 2, 'path' => "/groups/{$group->id}",
        ]);

        Livewire::actingAs($this->admin)
            ->test(ErrorRateByRoute::class)
            ->assertOk()
            ->assertSee('pickem.group');
    });

    it('says no data rather than zero views for a route nothing counted', function () {
        // "Zero views but eleven errors" is an impossible pair that reads as
        // a catastrophe.
        ClientError::create([
            'fingerprint' => str_repeat('d', 40), 'kind' => 'error',
            'message' => 'boom', 'reports' => 11, 'path' => '/scoreboard',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ErrorRateByRoute::class)
            ->assertOk()
            ->assertSee('no data');
    });
});

describe('the performance table', function () {
    it('shows a duration for a slow query and a dash for an exception', function () {
        /*
         * One column, two meanings. Pulse writes a duration in milliseconds
         * for the four slow_* types and the OCCURRENCE TIMESTAMP for
         * `exception` — so an exception has no `worst` at all, and a dash is
         * the honest rendering of a measurement never taken. A 0 ms here is
         * the invented number the whole rule exists to stop.
         */
        DB::table('pulse_entries')->insert([
            [
                'timestamp' => now()->subMinutes(10)->getTimestamp(),
                'type' => 'slow_query',
                'key' => '["select * from `games`","app/Support/Thing.php:12"]',
                'value' => 2_400,
            ],
            [
                'timestamp' => now()->subMinutes(10)->getTimestamp(),
                'type' => 'exception',
                'key' => '["RuntimeException","app/Support/Thing.php:12"]',
                'value' => now()->subMinutes(10)->getTimestamp(),
            ],
        ]);

        Livewire::actingAs($this->admin)
            ->test(PerformanceTop::class)
            ->assertOk()
            ->assertSee('2,400 ms')
            ->assertSee('RuntimeException');
    });
});

describe('the advisor ledger', function () {
    it('says plainly when the routine has never reported', function () {
        // The advisor's cron lives outside this repository, so nothing in the
        // scheduler can call it overdue. An empty ledger is a real state and
        // it points at the cron rather than at the app.
        Livewire::actingAs($this->admin)
            ->test(AdvisorLedger::class)
            ->assertOk()
            ->assertSee('No passes recorded')
            ->assertSee('Check its cron, not this app');
    });

    it('shows a failed pass as a row, never as an absence', function () {
        // A routine that dies silently is indistinguishable from one that
        // never ran, which is the failure a ledger exists to prevent.
        $run = FeedRun::begin(FeedRun::ADVISOR, null);
        $run->fail('the telemetry endpoint timed out', 0, 1_000);

        Livewire::actingAs($this->admin)
            ->test(AdvisorLedger::class)
            ->assertOk()
            ->assertSee('the telemetry endpoint timed out')
            ->assertDontSee('No passes recorded');
    });
});
