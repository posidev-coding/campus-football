<?php

use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * public/robots.txt (CFB-142). It used to be an explicit allow-all, and the
 * detail screens that arm an ESPN fetch were read almost only by crawlers.
 *
 * The web server answers /robots.txt from public/ before PHP is reached, so a
 * GET through the test client would only ask the router. These read the file
 * the web server serves, and hold it against the routing table so a renamed
 * route cannot leave a stale pattern behind.
 */

/**
 * The file's groups, in order, as RFC 9309 reads them: consecutive
 * User-agent lines open one group, and the rules after them belong to it.
 *
 * @return list<array{agents: list<string>, disallow: list<string>, crawl_delay: ?string}>
 */
function robotsGroups(): array
{
    $groups = [];
    $readingAgents = false;

    foreach (file(public_path('robots.txt'), FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim(Str::before($line, '#'));

        if ($line === '') {
            continue;
        }

        [$field, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
        $field = strtolower($field);

        if ($field === 'user-agent') {
            if (! $readingAgents) {
                $groups[] = ['agents' => [], 'disallow' => [], 'crawl_delay' => null];
            }

            $groups[array_key_last($groups)]['agents'][] = $value;
            $readingAgents = true;

            continue;
        }

        $readingAgents = false;

        match ($field) {
            'disallow' => $groups[array_key_last($groups)]['disallow'][] = $value,
            'crawl-delay' => $groups[array_key_last($groups)]['crawl_delay'] = $value,
            default => throw new RuntimeException("robots.txt has a line nothing here reads: {$line}"),
        };
    }

    return $groups;
}

/** @return array{agents: list<string>, disallow: list<string>, crawl_delay: ?string} */
function robotsGroupFor(string $agent): array
{
    $group = collect(robotsGroups())->first(fn (array $group): bool => in_array($agent, $group['agents'], true));

    expect($group)->not->toBeNull("robots.txt has no group for {$agent}");

    return $group;
}

/** Is this route behind a sign-in or a token? */
function routeIsPrivate(RegisteredRoute $route): bool
{
    return collect($route->gatherMiddleware())->contains(fn ($middleware): bool => is_string($middleware) && (
        $middleware === 'auth'
        || str_starts_with($middleware, 'auth:')
        || str_contains($middleware, 'Authenticate')
        || str_contains($middleware, 'EnsureOpsToken')
    ));
}

/** Every registered GET route a disallowed prefix covers. */
function routesUnder(string $prefix): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RegisteredRoute $route): bool => in_array('GET', $route->methods(), true)
            && str_starts_with('/'.$route->uri(), $prefix));
}

it('turns crawlers away from the three detail screens that arm an ESPN fetch', function () {
    $everyone = robotsGroupFor('*');

    expect($everyone['disallow'])->toBe(['/players/', '/games/', '/news/'])
        // Bing and Yandex honor it; Google ignores the line and uses its own rate.
        ->and($everyone['crawl_delay'])->toBe('10');
});

it('names only prefixes that cover a real, public detail screen', function () {
    $prefixes = collect(robotsGroups())->pluck('disallow')->flatten()->reject(fn (string $path): bool => $path === '');

    expect($prefixes)->not->toBeEmpty();

    foreach ($prefixes as $prefix) {
        // Plain prefixes only: `*` and `$` are extensions not every crawler reads.
        expect($prefix)->toStartWith('/')->toEndWith('/')
            ->and(Str::contains($prefix, ['*', '$']))->toBeFalse("{$prefix} uses a wildcard");

        $covered = routesUnder($prefix);

        expect($covered)->not->toBeEmpty("{$prefix} matches no registered route — was it renamed?");

        foreach ($covered as $route) {
            expect(routeIsPrivate($route))->toBeFalse("{$prefix} covers {$route->uri()}, which is behind a sign-in")
                ->and(str_contains($route->uri(), '{'))->toBeTrue("{$prefix} covers {$route->uri()}, which is not a detail screen");
        }
    }
});

it('leaves the screens worth indexing open', function (string $name, array $parameters = []) {
    $path = route($name, $parameters, absolute: false);

    foreach (robotsGroupFor('*')['disallow'] as $prefix) {
        expect($prefix === '' || ! str_starts_with($path, $prefix))
            ->toBeTrue("{$prefix} blocks {$path}");
    }
})->with([
    'home' => ['home'],
    'scoreboard' => ['scoreboard'],
    'standings' => ['standings'],
    'rankings' => ['rankings'],
    'stats' => ['stats'],
    'the player index' => ['players'],
    'the news index' => ['news'],
    'a team page' => ['team', ['team' => 'tennessee']],
    'the lobby' => ['pickem.lobby'],
]);

it('lets link previews through, so a shared game or article keeps its card', function (string $agent) {
    expect(robotsGroupFor($agent)['disallow'])->toBe(['']);
})->with(['Twitterbot', 'LinkedInBot', 'Slackbot', 'Discordbot', 'facebookexternalhit']);

it('names nothing behind a sign-in, which would only advertise it', function () {
    $robots = file_get_contents(public_path('robots.txt'));

    $private = collect(Route::getRoutes()->getRoutes())->filter(fn (RegisteredRoute $route): bool => routeIsPrivate($route));

    // The filter has to be finding the auth group, or this proves nothing.
    expect($private->map->uri()->all())->toContain('account');

    foreach ($private as $route) {
        $staticPart = trim(Str::before($route->uri(), '{'), '/');

        if ($staticPart !== '') {
            expect($robots)->not->toContain('/'.$staticPart);
        }
    }

    expect($robots)->not->toContain('/ops')
        ->not->toContain('/admin')
        ->not->toContain('/groups')
        ->not->toContain('/account');
});
