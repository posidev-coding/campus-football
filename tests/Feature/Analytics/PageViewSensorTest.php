<?php

use App\Actions\RecordActivity;
use App\Enums\ActivityKind;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Support\Release;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/*
 * Phase 2 of docs/plans/analytics.md: the sensor that finally counts a screen.
 *
 * These speak to a REAL Redis on database 15 (pinned in phpunit.xml) for the
 * reason UxFunnelTest does — the guarantee under test is "the request path
 * never touches MySQL", and a fake that only differs under test is exactly
 * where that class of bug hides.
 */

beforeEach(function () {
    Redis::connection('pulse')->flushdb();
});

/** Every entry buffered on the stream, in order, as flat field maps. */
function streamEntries(): array
{
    return array_values(array_map(
        fn ($fields) => (array) $fields,
        (array) Redis::connection('pulse')->xRange(RecordActivity::STREAM, '-', '+'),
    ));
}

function firstEntry(): array
{
    return streamEntries()[0] ?? [];
}

/**
 * Point the pulse connection at a closed port.
 *
 * The manager snapshots its config at construction, so `config()->set()` alone
 * changes nothing — a test written that way passes against a perfectly healthy
 * Redis and proves the opposite of what it claims.
 */
function breakRedis(): void
{
    app()->singleton('redis', fn ($app) => new RedisManager($app, 'phpredis', [
        'client' => 'phpredis',
        'pulse' => ['host' => '127.0.0.1', 'port' => 65_000, 'database' => 15, 'timeout' => 0.2],
    ]));

    Redis::clearResolvedInstances();
}

describe('what counts as a screen', function () {
    it('records one entry for one GET of an HTML route', function () {
        $this->get(route('scoreboard'))->assertOk();

        expect(streamEntries())->toHaveCount(1);

        expect(firstEntry())
            ->toMatchArray(['kind' => 'page_view', 'route' => 'scoreboard', 'via_navigate' => '0']);
    });

    it('counts a wire:navigate hop as a screen and sets via_navigate', function () {
        // The hop is a full GET carrying the header — the majority of screens
        // read inside the app, and the reason the sensor cannot live in a
        // mount hook.
        $this->withHeader('X-Livewire-Navigate', '1')->get(route('rankings'))->assertOk();

        expect(firstEntry())->toMatchArray(['route' => 'rankings', 'via_navigate' => '1']);
    });

    it('ignores a successful HTML POST, without knowing a single URI', function () {
        /*
         * GET is the WHOLE filter for component updates: every `wire:poll`,
         * every property update and every upload is a POST, and the sensor
         * never learns one of their URIs — a URI list goes stale the next
         * time Livewire renames its endpoint, and it goes stale silently.
         *
         * So this proves the rule on a route that answers 200 with HTML on
         * both verbs: the GET is counted and the POST is not, and the only
         * difference between them is the method.
         */
        Route::middleware('web')->group(function () {
            Route::get('/__sensor-probe', fn () => response('<html>ok</html>'))->name('sensor.probe');
            Route::post('/__sensor-probe', fn () => response('<html>ok</html>'));
        });

        $this->post('/__sensor-probe')->assertOk();

        expect(streamEntries())->toBe([]);

        $this->get('/__sensor-probe')->assertOk();

        expect(streamEntries())->toHaveCount(1);
    });

    it('ignores a Livewire endpoint however its route name is prefixed', function () {
        /*
         * Livewire registers its update route as `default-livewire.update`,
         * not `livewire.update` — so a `str_starts_with` on `livewire.`
         * matches nothing and every component update walks through as a
         * screen. The name is matched ANYWHERE for that reason, and this
         * probe wears the real prefix.
         */
        expect(collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => (string) $route->getName())
            ->contains('default-livewire.update'))->toBeTrue();

        Route::middleware('web')
            ->get('/__livewire-probe', fn () => response('<html>ok</html>'))
            ->name('default-livewire.probe');

        $this->get('/__livewire-probe')->assertOk();

        expect(streamEntries())->toBe([]);
    });

    it('ignores a redirect to login, a 403 and a JSON response', function () {
        // A 302 to the login screen is not a screen somebody read; the page
        // they were sent to is, and it records itself.
        $this->get(route('account'))->assertRedirect(route('login'));

        // A private clubhouse the reader has no seat in.
        [, $group] = pickemContest();
        $this->actingAs(pickemAdmin())->get(route('pickem.build', $group))->assertForbidden();

        $this->get(route('manifest'))->assertOk();

        expect(streamEntries())->toBe([]);
    });

    it('ignores the admin panel, which is a staff surface and not product traffic', function () {
        $this->actingAs(User::factory()->create(['admin' => true]))
            ->get('/admin')
            ->assertSuccessful();

        expect(collect(streamEntries())->pluck('route')->all())
            ->not->toContain('filament.admin.pages.dashboard');
    });

    it('ignores an ordinary XHR that is not a navigate hop', function () {
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route('scoreboard'))->assertOk();

        expect(streamEntries())->toBe([]);
    });
});

describe('who and what it records', function () {
    it('gives a guest a 32-hex visitor and never carries the raw session id', function () {
        $this->get(route('home'))->assertOk();

        $entry = firstEntry();

        expect($entry['visitor'])->toMatch('/^[0-9a-f]{32}$/')
            ->and($entry['user_id'])->toBe('')
            ->and($entry['audience'])->toBe((string) ActivityEvent::GUEST);

        /*
         * The session id IS the session cookie. A pipeline that copied it
         * into a table would turn a counting store into a hijacking kit, so
         * this asserts against the WHOLE payload rather than one field — a
         * new field carrying it would pass a per-column check.
         */
        expect(implode('|', $entry))->not->toContain(session()->getId());
    });

    it('records a member as audience 1 and an admin as audience 2', function () {
        $this->actingAs($member = User::factory()->create())->get(route('home'))->assertOk();

        expect(firstEntry())->toMatchArray([
            'user_id' => (string) $member->id,
            'visitor' => '',
            'audience' => (string) ActivityEvent::MEMBER,
        ]);

        Redis::connection('pulse')->flushdb();

        // Excluding the founder's own browsing is the whole point at pilot
        // scale, and the drain runs minutes later and cannot ask.
        $this->actingAs(User::factory()->create(['admin' => true]))->get(route('home'))->assertOk();

        expect(firstEntry()['audience'])->toBe((string) ActivityEvent::STAFF);
    });

    it('parses the client cookie, and leaves both nulls without it', function () {
        $this->get(route('scoreboard'))->assertOk();

        // The first response of a session goes out before the cookie is
        // written. Empty, not a guessed phone width.
        expect(firstEntry())->toMatchArray(['viewport' => '', 'standalone' => '']);

        Redis::connection('pulse')->flushdb();

        $this->withUnencryptedCookie(RecordActivity::COOKIE, 'w390.s1')
            ->get(route('scoreboard'))
            ->assertOk();

        expect(firstEntry())->toMatchArray(['viewport' => '390', 'standalone' => '1']);
    });

    it('reads nothing at all out of a malformed cookie', function () {
        foreach (['garbage', 'w390', '<script>', 'w390.s2', "w390.s1\nx"] as $value) {
            Redis::connection('pulse')->flushdb();

            $this->withUnencryptedCookie(RecordActivity::COOKIE, $value)->get(route('scoreboard'));

            expect(firstEntry())->toMatchArray(['viewport' => '', 'standalone' => '']);
        }
    });

    it('drops an impossible width rather than clamping it to a bucket edge', function () {
        foreach (['w0.s0', 'w1.s0', 'w99999.s0'] as $value) {
            Redis::connection('pulse')->flushdb();

            $this->withUnencryptedCookie(RecordActivity::COOKIE, $value)->get(route('scoreboard'));

            /*
             * Null, never the nearest sane width: a clamp would invent a
             * bucket boundary out of a number already known to be a lie, and
             * `Compact` is the bucket every layout bug shows up in. The
             * standalone flag beside it is still a fact and is still read.
             */
            expect(firstEntry())->toMatchArray(['viewport' => '', 'standalone' => '0']);
        }
    });

    it('stamps the release so a regression can be read against its deploy', function () {
        $this->get(route('scoreboard'))->assertOk();

        expect(firstEntry()['release'])->toBe((string) Release::version());
    });
});

describe('the facet allowlist', function () {
    it('records the clubhouse stop, and nothing else on any other screen', function () {
        [$member, $group] = pickemContest();

        $this->actingAs($member)->get(route('pickem.group', $group).'?view=talk')->assertOk();

        expect(firstEntry()['facet'])->toBe('talk');

        Redis::connection('pulse')->flushdb();

        // Not an allowlisted stop: null, never the raw value.
        $this->actingAs($member)->get(route('pickem.group', $group).'?view=../etc')->assertOk();

        expect(firstEntry()['facet'])->toBe('');

        Redis::connection('pulse')->flushdb();

        // The same parameter on a screen that is not allowed one is ignored.
        $this->get(route('scoreboard').'?view=talk')->assertOk();

        expect(firstEntry()['facet'])->toBe('');
    });

    it('allows exactly the stops the clubhouse renders', function () {
        /*
         * The sensor holds its own list — the clubhouse's is a private const
         * inside a single-file component and cannot be imported — so this is
         * what stops the two drifting. A sixth stop added to the screen and
         * not here would be recorded as null, and "nobody opens that tab"
         * would be the sensor's answer rather than the readers'.
         */
        $source = file_get_contents(resource_path('views/livewire/group.blade.php'));

        expect(preg_match("/private const VIEWS = \[(.+?)\];/s", $source, $match))->toBe(1);

        preg_match_all("/'([a-z]+)'/", $match[1], $stops);

        expect(RecordActivity::FACETS)->toBe($stops[1]);
    });
});

describe('the cost of measuring', function () {
    it('writes nothing to MySQL on the request path', function () {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs(User::factory()->create())->get(route('scoreboard'))->assertOk();

        expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'activity_events'))->all())
            ->toBe([]);

        expect(ActivityEvent::count())->toBe(0)
            ->and(streamEntries())->toHaveCount(1);
    });

    it('serves the page when Redis is unreachable', function () {
        // A counter is never worth a 500 on a screen. The stream write is the
        // last thing that happens on a request, and it happens after the
        // response has already been sent.
        breakRedis();

        Log::spy();

        $this->get(route('scoreboard'))->assertOk();

        // Swallowed AND said out loud: a sensor that fails silently reads as
        // a quiet week, which is the one failure nobody goes looking for.
        Log::shouldHaveReceived('debug')->withArgs(
            fn (string $message) => $message === 'Could not record an activity event.',
        )->once();
    });
});

describe('the cookie', function () {
    it('is written once before paint and excepted from encryption once', function () {
        /*
         * Two halves of one fact, and either alone is silently useless: a
         * cookie nobody writes reports nothing, and a plaintext cookie the
         * encrypter is not told about arrives as null forever, because
         * EncryptCookies swallows the DecryptException.
         */
        $head = file_get_contents(resource_path('views/partials/head.blade.php'));
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        expect(substr_count($head, RecordActivity::COOKIE))->toBe(1)
            ->and(substr_count($bootstrap, 'RecordActivity::COOKIE'))->toBe(1)
            ->and($bootstrap)->toContain('encryptCookies(except:');
    });
});

describe('the drain', function () {
    it('lands buffered entries in MySQL, derives the league day, and empties the stream', function () {
        $this->travelTo('2026-09-06 01:30:00');

        $member = User::factory()->create();

        $this->actingAs($member)
            ->withUnencryptedCookie(RecordActivity::COOKIE, 'w390.s0')
            ->get(route('scoreboard'))
            ->assertOk();

        expect(app(RecordActivity::class)->pending())->toBe(1);

        expect(app(RecordActivity::class)->drain())->toBe(1);

        $row = ActivityEvent::sole();

        // 01:30 UTC Saturday-night is still Friday the 5th in the league's
        // own timezone, and that is the day the screen was read on.
        expect($row->day->toDateString())->toBe('2026-09-05')
            ->and($row->hour)->toBe(21)
            ->and($row->kind)->toBe(ActivityKind::PageView)
            ->and($row->user_id)->toBe($member->id)
            ->and($row->visitor)->toBeNull()
            ->and($row->viewport)->toBe(390)
            ->and($row->standalone)->toBeFalse()
            ->and($row->via_navigate)->toBeFalse();

        expect(streamEntries())->toBe([])
            ->and($member->fresh()->last_seen_at->toDateTimeString())->toBe('2026-09-06 01:30:00');
    });

    it('maps an unreported value back to null, never to zero or false', function () {
        $this->get(route('scoreboard'))->assertOk();

        app(RecordActivity::class)->drain();

        $row = ActivityEvent::sole();

        // The whole of ViewportBucket::Unknown depends on this: `''` on the
        // stream is "not reported", and a 0 width or a false standalone here
        // would be a claim nobody made.
        expect($row->viewport)->toBeNull()
            ->and($row->standalone)->toBeNull()
            ->and($row->user_id)->toBeNull()
            ->and($row->facet)->toBeNull();
    });

    it('writes one row when the same entry is drained twice', function () {
        $this->get(route('scoreboard'))->assertOk();

        $entries = (array) Redis::connection('pulse')->xRange(RecordActivity::STREAM, '-', '+');

        expect(app(RecordActivity::class)->drain())->toBe(1);

        // The crash window: the insert landed and the XDEL did not, so the
        // next drain re-reads the same entry. The unique stream_id is what
        // makes that cost a read rather than a duplicate row.
        foreach ($entries as $id => $fields) {
            Redis::connection('pulse')->xAdd(RecordActivity::STREAM, (string) $id, (array) $fields);
        }

        expect(app(RecordActivity::class)->drain())->toBe(0)
            ->and(ActivityEvent::count())->toBe(1);
    });

    it('drops an entry whose kind has left the vocabulary', function () {
        Redis::connection('pulse')->xAdd(RecordActivity::STREAM, '*', [
            'kind' => 'retired_thing',
            'route' => 'scoreboard',
            'audience' => '0',
            'occurred_at' => '2026-09-05 18:00:00',
            'via_navigate' => '0',
        ]);

        // The vocabulary is the enum's, not Redis's — the rule
        // RecordUxEvent::rollUp() already follows for a retired signal.
        expect(app(RecordActivity::class)->drain())->toBe(0)
            ->and(ActivityEvent::count())->toBe(0)
            ->and(streamEntries())->toBe([]);
    });

    it('reports null pending, not zero, when Redis cannot be reached', function () {
        breakRedis();

        // Zero is a drain keeping up. A monitor that printed it for an
        // unreachable Redis would report the healthiest number for the worst
        // state.
        expect(app(RecordActivity::class)->pending())->toBeNull();
    });
});
