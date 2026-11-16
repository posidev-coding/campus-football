<?php

use App\Ai\Agents\StatQuestion;
use App\Models\User;
use App\Support\PulseExceptions;
use App\Support\StatAnswer;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

/*
 * CFB-122. Production refused five locked-property updates on the search
 * components, two hits each, the last five within two seconds of each other:
 *
 *   livewire:search [searchCounted]
 *   livewire:search-panel [answer] [asked] [decline] [searchCounted]
 *
 * The brief was to find why the BROWSER sends them back. It does not. Livewire's
 * client posts only the keys whose value differs from the snapshot it holds,
 * and nothing in a view or a script writes any of these four names, so no
 * interaction on the page can put one in a payload. What does produce exactly
 * those five rows, and nothing else, is posting every public property of every
 * component on a SIGNED-OUT Home back to the update endpoint one at a time —
 * a probe, not a person. Signed in, the same probe also trips help-sheet's
 * locked `path`, which production never logged.
 *
 * So these tests pin three things: the fingerprint, the real interactions
 * round-tripping cleanly, and the source staying free of any writer of a
 * locked name — the one way a browser COULD start sending these.
 */

/**
 * Every component snapshot a page rendered, in document order.
 *
 * @return list<string>
 */
function renderedSnapshots(string $html): array
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    return array_map(fn (string $encoded) => html_entity_decode($encoded, ENT_QUOTES), $matches[1]);
}

/** The plain value of a dehydrated property, without a synthesizer's tuple. */
function dehydratedValue(mixed $value): mixed
{
    $isTuple = is_array($value) && array_is_list($value) && count($value) === 2
        && is_array($value[1]) && array_key_exists('s', $value[1]);

    return $isTuple ? $value[0] : $value;
}

/**
 * Post ONE property of every component back at the endpoint, one request
 * each, and return where Pulse would file every refusal.
 *
 * @return list<string>
 */
function probeEveryProperty(string $html): array
{
    $rows = [];

    foreach (renderedSnapshots($html) as $snapshot) {
        foreach (json_decode($snapshot, true)['data'] as $property => $value) {
            test()->withoutExceptionHandling();

            try {
                test()->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
                    'components' => [[
                        'snapshot' => $snapshot,
                        'updates' => [$property => dehydratedValue($value)],
                        'calls' => [],
                    ]],
                ]);
            } catch (CannotUpdateLockedPropertyException $e) {
                $rows[] = PulseExceptions::livewireLocation(app('request'), $e);
            }
        }
    }

    return $rows;
}

/**
 * One browser commit: the snapshot the page holds, ONLY the keys that changed
 * in the browser, and any calls. Returns the snapshot the browser would carry
 * into its next commit.
 *
 * @param  array<string, mixed>  $updates
 * @param  list<array{method: string, params: list<mixed>}>  $calls
 */
function browserCommit(string $snapshot, array $updates, array $calls = []): string
{
    test()->withoutExceptionHandling();

    $response = test()->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => array_map(fn (array $call) => [...$call, 'metadata' => []], $calls),
        ]],
    ])->assertOk();

    return $response->json('components.0.snapshot');
}

/** The snapshot a page rendered for one named component. */
function snapshotOf(string $html, string $name): string
{
    foreach (renderedSnapshots($html) as $snapshot) {
        if (json_decode($snapshot, true)['memo']['name'] === $name) {
            return $snapshot;
        }
    }

    throw new RuntimeException("The page rendered no {$name}.");
}

/** @return array<string, mixed> */
function snapshotData(string $snapshot): array
{
    return array_map(dehydratedValue(...), json_decode($snapshot, true)['data']);
}

describe('the production fingerprint', function () {
    it('is exactly what a per-property probe of a signed-out Home is refused', function () {
        expect(probeEveryProperty($this->get(route('home'))->assertOk()->getContent()))->toBe([
            'livewire:search [searchCounted]',
            'livewire:search-panel [answer]',
            'livewire:search-panel [asked]',
            'livewire:search-panel [decline]',
            'livewire:search-panel [searchCounted]',
        ]);
    });

    it('would also name help-sheet if the sender were signed in, which production never did', function () {
        $this->actingAs(User::factory()->create());

        expect(probeEveryProperty($this->get(route('home'))->assertOk()->getContent()))
            ->toContain('livewire:help-sheet [path]');
    });
});

describe('what a browser actually sends', function () {
    it('round-trips typing on every search surface and counts it once', function (string $route, string $name) {
        $this->actingAs(User::factory()->create());

        $snapshot = snapshotOf($this->get(route($route))->assertOk()->getContent(), $name);

        // Below the minimum, then past it, then two further keystrokes — the
        // debounce ticks that would each count without the locked flag.
        $snapshot = browserCommit($snapshot, ['q' => 't']);
        expect(snapshotData($snapshot)['searchCounted'])->toBeFalse();

        $snapshot = browserCommit($snapshot, ['q' => 'tenn']);
        expect(snapshotData($snapshot)['searchCounted'])->toBeTrue();

        $snapshot = browserCommit($snapshot, ['q' => 'tennes']);
        $snapshot = browserCommit($snapshot, ['q' => 'tennessee']);

        expect(snapshotData($snapshot))->toMatchArray(['q' => 'tennessee', 'searchCounted' => true]);
    })->with([
        'Home panel' => ['home', 'search-panel'],
        'the palette' => ['home', 'search'],
        '/search' => ['search', 'search-page'],
    ]);

    it('round-trips an ask, its decline, and the keystroke after it', function (string $route, string $name) {
        config(['cfb.ai_enabled' => true, 'cfb.ai_answers' => true]);

        // A player we hold nothing for: an honest decline, so the server
        // moves all three locked ask slots off their defaults.
        StatQuestion::fake([[
            'answerable' => true,
            'subject' => 'player',
            'name' => 'Nobody We Hold',
            'metric' => 'passing.passingYards',
            'timeframe' => 'season',
            'season_year' => null,
            'note' => '',
        ]]);

        $this->actingAs(User::factory()->create());

        $question = 'How many passing yards did Nobody We Hold throw?';
        $snapshot = snapshotOf($this->get(route($route))->assertOk()->getContent(), $name);

        $snapshot = browserCommit($snapshot, ['q' => $question], [['method' => 'ask', 'params' => []]]);

        expect(snapshotData($snapshot))->toMatchArray(['asked' => $question, 'answer' => null])
            ->and(snapshotData($snapshot)['decline'])->not->toBe(StatAnswer::RESOLVED);

        // The next keystroke carries the server's own values in the snapshot
        // and only `q` in its updates — the locks have nothing to refuse.
        $snapshot = browserCommit($snapshot, ['q' => $question.' ']);

        expect(snapshotData($snapshot))->toMatchArray(['asked' => $question, 'searchCounted' => true]);
    })->with([
        'Home panel' => ['home', 'search-panel'],
        '/search' => ['search', 'search-page'],
    ]);
});

/*
 * The only way a browser could send a locked name: something on the page
 * writing it. A `wire:model` on it, an Alpine `$wire.name =`, a `$set` or an
 * `entangle` — any of those puts the name in the client's diff, and the lock
 * refuses the whole commit, losing whatever the reader did.
 */
it('never lets a view or a script write a locked property', function () {
    $declarations = collect([
        ...glob(app_path('Livewire/Concerns/*.php')),
        ...glob(resource_path('views/livewire/*.blade.php')),
    ]);

    $locked = $declarations
        ->flatMap(function (string $path) {
            preg_match_all('/#\[Locked\]\s*public\s+[^$;]*\$(\w+)/', file_get_contents($path), $matches);

            return $matches[1];
        })
        ->unique()
        ->values();

    // Not vacuous: the sweep has to know about the names production refused.
    expect($locked->all())->toContain('searchCounted', 'answer', 'asked', 'decline', 'path');

    $sources = collect([
        ...(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS))),
        ...(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS))),
    ])->map(fn (SplFileInfo $file) => $file->getPathname())
        ->filter(fn (string $path) => str_ends_with($path, '.php') || str_ends_with($path, '.js'));

    $writers = [];

    foreach ($sources as $path) {
        $source = file_get_contents($path);

        foreach ($locked as $name) {
            $patterns = [
                '/wire:model(?:\.[\w.]+)?=["\']'.$name.'(?:\.[\w.]+)?["\']/',
                '/\$wire\.'.$name.'\s*=(?!=)/',
                '/\$wire\.(?:\$set|set|\$entangle|entangle)\(\s*["\']'.$name.'["\']/',
                '/@entangle\(\s*["\']'.$name.'["\']/',
                '/\$set\(\s*["\']'.$name.'["\']/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source)) {
                    $writers[] = str_replace(base_path().'/', '', $path)." writes {$name}";
                }
            }
        }
    }

    expect($writers)->toBe([]);
});
