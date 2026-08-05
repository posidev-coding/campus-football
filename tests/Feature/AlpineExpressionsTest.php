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
