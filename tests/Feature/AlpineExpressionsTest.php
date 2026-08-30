<?php

/*
 * Alpine compiles an expression as `__self.result = <expr>` and only wraps it
 * in an IIFE when the expression STARTS with `let` or `const`:
 *
 *     /^(let|const)\s/.test(expression.trim()) ? `(async()=>{ ${expr} })()` : expr
 *
 * So a multi-statement body that opens with anything else — a block comment is
 * the easy way in — compiles to `result = const io = …`, which is a
 * SyntaxError. Alpine catches it, and the directive silently never runs.
 *
 * That is the expensive part: nothing throws where you are looking. Home's team
 * swiper lost its IntersectionObserver this way, so `active` stayed 0 and the
 * dots never tracked a swipe. The feature was not broken, it was INERT.
 *
 * The fix is always the same shape: put the body in an `x-data` method, where
 * declarations and comments are both legal, and leave a plain call behind.
 */

use Symfony\Component\Finder\Finder;

/**
 * Every Alpine attribute whose value Alpine compiles as a single expression.
 * `x-data` is excluded deliberately — it is evaluated as an object literal, so
 * comments and methods inside it are always fine, which is exactly why it is
 * where a multi-statement body belongs.
 *
 * @return list<array{file: string, attribute: string, expression: string}>
 */
function alpineExpressions(): array
{
    $found = [];

    $files = Finder::create()->files()->in(resource_path('views'))->name('*.blade.php');

    foreach ($files as $file) {
        preg_match_all(
            '/\b(x-init|x-effect)="([^"]*)"/s',
            $file->getContents(),
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as [, $attribute, $expression]) {
            $found[] = [
                'file' => str_replace(resource_path('views').'/', '', $file->getPathname()),
                'attribute' => $attribute,
                'expression' => $expression,
            ];
        }
    }

    return $found;
}

/**
 * True when Alpine will fail to compile this body and swallow the error.
 */
function alpineSilentlyFails(string $body): bool
{
    $body = trim($body);

    return preg_match('/(^|[\s;{(])(let|const)\s/', $body) === 1
        && preg_match('/^(let|const)\s/', $body) !== 1;
}

it('finds Alpine expressions to check, or the sweep is vacuous', function () {
    // A regex that silently matches nothing would make every assertion below
    // pass forever. Home and the scoreboard both carry one.
    expect(alpineExpressions())->not->toBeEmpty();
});

it('recognizes the shape that broke the swiper', function () {
    // The detector itself, in both directions — otherwise a guard that can
    // never fire reads exactly like a codebase that is clean.
    expect(alpineSilentlyFails("/* why */\nconst io = new IntersectionObserver(() => {})"))->toBeTrue()
        // Starting with the declaration is the case Alpine DOES wrap.
        ->and(alpineSilentlyFails('const io = 1; io()'))->toBeFalse()
        // And a plain call, which is what the swiper uses now.
        ->and(alpineSilentlyFails('trackCards()'))->toBeFalse();
});

it('never declares a variable Alpine will refuse to compile', function () {
    $broken = [];

    foreach (alpineExpressions() as $expression) {
        if (alpineSilentlyFails($expression['expression'])) {
            $broken[] = "{$expression['file']} ({$expression['attribute']})";
        }
    }

    expect($broken)->toBe([], implode(', ', $broken)
        .' — declares a variable but does not START with it, so Alpine compiles'
        .' `result = const …` and the directive never runs. Move the body into'
        .' an x-data method and call it from here.');
});

/*
 * The same failure mode, one directive over: `wire:sort` takes a bare METHOD
 * NAME, and Livewire passes the moved item and its new 0-based index itself.
 *
 * Writing the call out — `reorder($item, $position)` — looks more explicit and
 * silently sends NULLs, because `contextualizeExpression()` rewrites every
 * identifier that is not in the element's Alpine scope to `$wire.<ident>`, and
 * the $item/$position magics arrive as an evaluator OPTION rather than element
 * scope. The call became `$wire.reorder($wire.$item, $wire.$position)`, both
 * undefined, and the server rejected a null team id.
 *
 * Only reachable by a real pointer drag — SortableJS ignores synthetic events,
 * so no automated interaction test can reach it. The rendered attribute is
 * therefore what gets asserted.
 *
 * @return list<array{file: string, expression: string}>
 */
function wireSortExpressions(): array
{
    $found = [];

    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        preg_match_all('/\bwire:sort="([^"]*)"/', $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as [, $expression]) {
            $found[] = [
                'file' => str_replace(resource_path('views').'/', '', $file->getPathname()),
                'expression' => $expression,
            ];
        }
    }

    return $found;
}

it('finds a wire:sort to check, or that sweep is vacuous too', function () {
    expect(wireSortExpressions())->not->toBeEmpty();
});

it('passes wire:sort a bare method name, never a call expression', function () {
    $broken = [];

    foreach (wireSortExpressions() as $sort) {
        if (preg_match('/^[a-zA-Z_]\w*$/', trim($sort['expression'])) !== 1) {
            $broken[] = "{$sort['file']} (wire:sort=\"{$sort['expression']}\")";
        }
    }

    expect($broken)->toBe([], implode(', ', $broken)
        .' — wire:sort takes a bare method name. Livewire passes the item and'
        .' its 0-based position itself; spelling the call out rewrites $item to'
        .' $wire.$item, which is undefined, and the handler receives null.');
});

/*
 * The same failure mode, one attribute over: an `x-data` attached inside a
 * Blade conditional opened WITHIN the element's own tag.
 *
 * A `@if` wrapping whole elements is ordinary and fine — the scope and
 * everything that reads it appear and disappear together. Opening the
 * conditional INSIDE a tag is a different animal: the element renders either
 * way, and only its Alpine scope is keyed by the prop.
 *
 * The verify callout shipped that shape, so ONE Livewire component had two
 * scopes — Home and Account defined `dismissed`, the five picks surfaces
 * defined nothing — while `x-show="! dismissed"` and the dismiss button's
 * `dismissed = true` read a variable that existed in only one of them.
 *
 * Alpine reports the miss as a bare ReferenceError from its evaluator, with no
 * element and no file attached, so it surfaces against whatever path the reader
 * is standing on: the production report landed on /verify-email, a screen that
 * has no callout on it at all.
 *
 * The fix is always the same shape: attach the scope unconditionally and let
 * the flag decide only who READS it.
 */

/**
 * Counts the `x-data` attributes in one Blade source whose own tag opens a
 * Blade conditional before them.
 */
function conditionallyAttachedScopes(string $source): int
{
    // A Blade comment is not markup, and the explanations around this trap
    // name `@if` — counting those would make a commented template look broken.
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    if (preg_match_all('/\bx-data\s*=\s*"/', $source, $scopes, PREG_OFFSET_CAPTURE) === 0) {
        return 0;
    }

    $conditional = 0;

    foreach ($scopes[0] as [, $offset]) {
        $tag = strrpos(substr($source, 0, $offset), '<');

        if ($tag === false) {
            continue;
        }

        preg_match_all('/@(if|unless|endif|endunless)\b/', substr($source, $tag, $offset - $tag), $directives);

        $depth = 0;

        foreach ($directives[1] as $directive) {
            $depth += in_array($directive, ['if', 'unless'], true) ? 1 : -1;
        }

        if ($depth > 0) {
            $conditional++;
        }
    }

    return $conditional;
}

/**
 * Every Blade template that declares an Alpine scope.
 *
 * @return list<string>
 */
function bladeFilesWithScopes(): array
{
    $found = [];

    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        if (str_contains($file->getContents(), 'x-data')) {
            $found[] = str_replace(resource_path('views').'/', '', $file->getPathname());
        }
    }

    return $found;
}

it('finds Alpine scopes to check, or that sweep is vacuous too', function () {
    // Home, the banners and the callout all declare one.
    expect(bladeFilesWithScopes())->not->toBeEmpty();
});

it('recognizes a scope keyed to a conditional inside its own tag', function () {
    // The shape that shipped: the element renders either way, the scope does not.
    expect(conditionallyAttachedScopes('<div @if ($flag) x-data="{ dismissed: false }" @endif>'))->toBe(1)
        ->and(conditionallyAttachedScopes('<div @unless ($flag) x-data="{ dismissed: false }" @endunless>'))->toBe(1)
        // A conditional wrapping whole ELEMENTS is the ordinary case: the
        // scope and its readers arrive and leave together.
        ->and(conditionallyAttachedScopes('@if ($flag)<div x-data="{ dismissed: false }"></div>@endif'))->toBe(0)
        // And the fix: scope unconditional, only the reader behind the flag.
        ->and(conditionallyAttachedScopes('<div x-data="{ dismissed: false }" @if ($flag) x-show="! dismissed" @endif>'))->toBe(0)
        // The sweep reads markup, not prose — a comment explaining the trap
        // must not read as the trap.
        ->and(conditionallyAttachedScopes('<div {{-- @if ($flag) --}} x-data="{ dismissed: false }">'))->toBe(0);
});

it('never keys an Alpine scope to a Blade conditional inside a tag', function () {
    $broken = [];

    foreach (bladeFilesWithScopes() as $file) {
        $count = conditionallyAttachedScopes(file_get_contents(resource_path("views/{$file}")));

        if ($count > 0) {
            $broken[] = "{$file} ({$count})";
        }
    }

    expect($broken)->toBe([], implode(', ', $broken)
        .' — attaches x-data inside a Blade conditional in the element\'s own'
        .' tag, so the same element renders with and without an Alpine scope.'
        .' Anything reading that scope throws a bare ReferenceError from'
        .' Alpine\'s evaluator, reported against whatever path the reader is'
        .' on. Attach the scope unconditionally and put only the READERS'
        .' behind the flag.');
});
