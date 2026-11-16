<?php

use App\Actions\PublishSlate;
use App\Enums\ContestMode;
use App\Enums\Poll;
use App\Jobs\FetchArticleStory;
use App\Jobs\SyncTeamNews;
use App\Models\Article;
use App\Models\BrandSetting;
use App\Models\Ranking;
use App\Models\Recruit;
use App\Models\Team;
use App\Support\Remember;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;

/*
 * A Redis stall mid-render used to 500 every page on the site (CFB-97).
 * `partials/head` is on every layout and reads the brand through the cache;
 * the cache is Redis; Blade wrapped the RedisException in a ViewException. The
 * brand_settings row the cache stood in front of was up the whole time.
 */

/**
 * Put the cache on Redis, and point Redis at a closed port.
 *
 * The suite runs the cache on `array`, so nothing here would touch Redis
 * without the first line. And the manager snapshots its config at
 * construction, so `config()->set()` on a connection changes nothing — a test
 * written that way passes against a perfectly healthy Redis.
 */
function breakCacheStore(): void
{
    app()->singleton('redis', fn ($app) => new RedisManager($app, 'phpredis', [
        'client' => 'phpredis',
        'options' => ['prefix' => 'cfb-outage-'],
        'cache' => ['host' => '127.0.0.1', 'port' => 65_000, 'database' => 1, 'timeout' => 0.2],
        'default' => ['host' => '127.0.0.1', 'port' => 65_000, 'database' => 0, 'timeout' => 0.2],
        'pulse' => ['host' => '127.0.0.1', 'port' => 65_000, 'database' => 15, 'timeout' => 0.2],
    ]));

    Redis::clearResolvedInstances();
    Cache::forgetDriver('redis');
    config(['cache.default' => 'redis']);
}

describe('the layout, while the cache store is down', function () {
    it('renders the stored brand from its row instead of a 500', function () {
        // A name only the ROW can supply, so a pass proves the source of
        // truth was read rather than the shipped default.
        BrandSetting::current()->update(['name' => 'Rocky Top Picks']);

        Log::spy();
        breakCacheStore();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<title>Rocky Top Picks</title>', escape: false);

        // Caught is not the same as hidden: the next stall has to be as
        // visible to the advisor as the last one was.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Cache store unavailable')
                && $context['store'] === 'redis'
                && $context['key'] === 'brand:settings')
            ->atLeast()->once();
    });
});

describe('a signed-in screen, while the cache store is down', function () {
    it('renders My Picks from the rows, with the nav dot honestly lit', function () {
        config()->set('cfb.pickem_open', true);

        // A published slate with no picks is a week that still needs the
        // reader, so the dot has to be ON. A default would read false, so a
        // lit dot proves the rows were read.
        [$commissioner, , $contest] = pickemContest(ContestMode::Classic);
        app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));

        Log::spy();
        breakCacheStore();

        // /picks names every read the app layout needs: the calendar under
        // the pulse, TeamGlance under the header search, and the dot.
        $this->actingAs($commissioner)
            ->get(route('pickem.home'))
            ->assertOk()
            ->assertSee('Picks waiting');

        foreach (['calendar:season:', 'glance:held:', 'glance:ranks', 'pickem-pulse:dot:'.$commissioner->id] as $key) {
            Log::shouldHaveReceived('warning')
                ->withArgs(fn (string $message, array $context) => str_starts_with($context['key'], $key))
                ->atLeast()->once();
        }
    });
});

describe('Remember::filledOrSource', function () {
    it('never stores an empty answer, so a draining sync cannot pin one', function () {
        $computed = 0;
        // An empty LIST, not null: a null is uncacheable by construction, so
        // a test built on one passes whether or not the guard holds.
        $compute = function () use (&$computed): array {
            $computed++;

            return [];
        };

        Remember::filledOrSource('probe', 60, $compute);
        Remember::filledOrSource('probe', 60, $compute);

        expect($computed)->toBe(2)
            ->and(Cache::has('probe'))->toBeFalse();
    });

    it('reads the source when the store is down, and never masks the source failing', function () {
        breakCacheStore();

        expect(Remember::filledOrSource('probe', 60, fn (): array => [2026]))->toBe([2026])
            ->and(fn () => Remember::filledOrSource('probe', 60, fn () => throw new RuntimeException('database is down')))
            ->toThrow(RuntimeException::class, 'database is down');
    });
});

describe('Remember::filled, while the cache store is down (CFB-112)', function () {
    it('reads the source instead of letting the RedisException escape, and says so', function () {
        Log::spy();
        breakCacheStore();

        expect(Remember::filled('probe:filled', 60, fn (): array => [2026, 2025]))->toBe([2026, 2025]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Cache store unavailable on read')
                && $context['store'] === 'redis'
                && $context['key'] === 'probe:filled')
            ->once();
    });

    it('returns the computed value when only the fill throws', function () {
        // A store that answers the read but stalls on the write: the value is
        // already in hand, so the write failing must not cost the page.
        Log::spy();
        Cache::shouldReceive('get')->with('probe:filled')->once()->andReturnNull();
        Cache::shouldReceive('put')->once()->andThrow(new RedisException('write stalled'));

        expect(Remember::filled('probe:filled', 60, fn (): array => [2026]))->toBe([2026]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Cache store unavailable on write')
                && $context['key'] === 'probe:filled')
            ->once();
    });

    it('never masks the source failing, and never stores an empty answer', function () {
        breakCacheStore();

        expect(fn () => Remember::filled('probe:filled', 60, fn () => throw new RuntimeException('database is down')))
            ->toThrow(RuntimeException::class, 'database is down');

        // Healthy store again: the guard must not have loosened the rule
        // filled() exists for. An empty LIST, since a null is uncacheable by
        // construction and would pass either way.
        config(['cache.default' => 'array']);
        $computed = 0;
        $compute = function () use (&$computed): array {
            $computed++;

            return [];
        };

        Remember::filled('probe:filled', 60, $compute);
        Remember::filled('probe:filled', 60, $compute);

        expect($computed)->toBe(2)
            ->and(Cache::has('probe:filled'))->toBeFalse();
    });
});

describe('Remember::orSource', function () {
    it('never masks the source failing — only the store', function () {
        breakCacheStore();

        expect(fn () => Remember::orSource('probe', 60, fn () => throw new RuntimeException('database is down')))
            ->toThrow(RuntimeException::class, 'database is down');
    });

    it('caches through a healthy store exactly as Cache::remember does', function () {
        $computed = 0;
        $compute = function () use (&$computed): bool {
            $computed++;

            return false;
        };

        // `false` is an answer, not a miss — the nav dot caches it.
        expect(Remember::orSource('probe', 60, $compute))->toBeFalse()
            ->and(Remember::orSource('probe', 60, $compute))->toBeFalse()
            ->and($computed)->toBe(1);
    });
});

describe('the data screens, while the cache store is down', function () {
    /*
     * Each of these screens read the cache through a bare Cache::remember on
     * the render path, so the next Redis stall was a 500 there even after the
     * layout's own reads were guarded (CFB-97, CFB-112).
     */
    it('renders :dataset from its rows instead of a 500', function (string $route) {
        Log::spy();
        breakCacheStore();

        $this->get(route($route))->assertOk();

        // Caught, not hidden: at least one of the screen's own reads warned.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Cache store unavailable'))
            ->atLeast()->once();
    })->with(['scoreboard', 'standings', 'rankings', 'teams', 'players', 'recruiting']);

    it('renders a team page and skips its news dispatch rather than firing one per render', function () {
        Queue::fake();
        Log::spy();
        $team = Team::factory()->create();
        breakCacheStore();

        $this->get(route('team', ['team' => $team, 'tab' => 'news']))->assertOk();

        Queue::assertNotPushed(SyncTeamNews::class);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $context['key'] === "team:news:dispatched:{$team->id}")
            ->once();
    });

    it('answers an article\'s story request without dispatching, and warns', function () {
        Queue::fake();
        Log::spy();
        $article = Article::factory()->create();
        $component = Livewire::test('article', ['article' => $article]);
        breakCacheStore();

        $component->call('requestStory')->assertOk();

        Queue::assertNotPushed(FetchArticleStory::class);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $context['key'] === "article:story:dispatched:{$article->id}")
            ->once();
    });
});

describe('Remember::oncePer', function () {
    it('runs the side effect once per window on a healthy store', function () {
        $ran = 0;

        expect(Remember::oncePer('probe:gate', 60, function () use (&$ran) {
            $ran++;
        }))->toBeTrue()
            ->and(Remember::oncePer('probe:gate', 60, function () use (&$ran) {
                $ran++;
            }))->toBeFalse()
            ->and($ran)->toBe(1);
    });
});

describe('the season menus, after a backfill lands', function () {
    /*
     * The pinned-empty bug Remember::filled exists for: a menu cached [] while
     * the rows were still draining served no options for the full TTL.
     */
    it('heals a rankings year menu cached empty', function () {
        $ranking = Ranking::factory()->create(['poll' => Poll::Ap->value]);
        Cache::put('rankings:years:'.Poll::Ap->value, [], 3600);
        Cache::put('rankings:published-polls', [], 3600);

        $component = Livewire::withQueryParams(['poll' => Poll::Ap->value])->test('rankings');

        expect($component->instance()->publishedPolls)->toBe([Poll::Ap->value])
            ->and($component->instance()->years)->toBe([$ranking->season->year]);
    });

    it('heals a recruiting class menu cached empty', function () {
        Recruit::create([
            'espn_id' => 256474, 'recruiting_class' => 2026, 'display_name' => 'Lamar Brown',
            'grade' => 94, 'national_rank' => 1,
        ]);
        Cache::put('recruiting:classes', [], 3600);

        expect(Livewire::test('recruiting')->instance()->classes)->toBe([2026]);
    });
});

describe('the read-throughs, statically', function () {
    /*
     * The outage tests above cover the screens that exist today. A new screen
     * that reaches for a bare Cache::remember reopens the 500 the moment it
     * lands, and nothing renders it with the store down. So the sweep is over
     * the source: every read-through goes through Remember.
     */
    it('leaves no bare Cache::remember on a path a render can reach', function () {
        $offenders = collect(File::allFiles(app_path()))
            ->merge(File::allFiles(resource_path('views')))
            ->filter(fn (SplFileInfo $file) => str_ends_with($file->getFilename(), '.php'))
            ->flatMap(function (SplFileInfo $file) {
                // Strip comments, so a docblock explaining why a read is NOT
                // Cache::remember is not itself an offender.
                $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', File::get($file->getPathname()));

                return preg_match('/Cache::(remember|rememberForever|flexible|sear)\(/', $code)
                    ? [str_replace(base_path().'/', '', $file->getPathname())]
                    : [];
            })
            ->values()
            ->all();

        expect($offenders)->toBe([]);
    });
});
