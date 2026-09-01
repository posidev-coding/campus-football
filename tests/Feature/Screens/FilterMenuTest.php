<?php

use Illuminate\Support\Facades\Blade;

/*
 * THE ONE DROPDOWN IDIOM, extended exactly once. An item may carry `href`
 * and NAVIGATE instead of setting a property, and a `hero` variant lets
 * the clubhouse wear the menu as its title. ChromeConsistencyTest still
 * bans every other dropdown species — this is what keeps the group
 * switcher inside the vocabulary rather than beside it.
 */

function renderFilterMenu(array $items, string $selected = 'a', string $variant = 'default'): string
{
    return Blade::render(
        '<x-filter-menu :items="$items" :selected="$selected" :variant="$variant" model="view" label="Pick one" key-prefix="fm" />',
        ['items' => $items, 'selected' => $selected, 'variant' => $variant],
    );
}

/** The one tag that carries the needle, from its `<` to its `>`. */
function tagWith(string $html, string $needle): string
{
    $at = strpos($html, $needle);

    expect($at)->not->toBeFalse("no tag carries {$needle}");

    $start = strrpos(substr($html, 0, $at), '<');
    $end = strpos($html, '>', $at);

    return substr($html, $start, $end - $start + 1);
}

it('navigates from an item that carries href, and never sets', function () {
    $html = renderFilterMenu([
        ['value' => 'a', 'label' => 'Alpha', 'href' => '/alpha'],
        ['value' => 'b', 'label' => 'Beta', 'href' => '/beta', 'note' => '3 open'],
    ]);

    $alpha = tagWith($html, 'wire:key="fm-a"');
    $beta = tagWith($html, 'wire:key="fm-b"');

    expect($alpha)->toStartWith('<a')
        ->toContain('href="/alpha"')
        ->toContain('wire:navigate')
        ->and($beta)->toContain('href="/beta"')
        ->and($html)->not->toContain('wire:click')
        // The note rides an ENABLED row now, as Flux's own suffix.
        ->toContain('3 open');
});

it('bolds the current item whether it navigates or sets', function () {
    $html = renderFilterMenu([
        ['value' => 'a', 'label' => 'Alpha', 'href' => '/alpha'],
        ['value' => 'b', 'label' => 'Beta'],
    ], selected: 'a');

    expect(tagWith($html, 'wire:key="fm-a"'))->toContain('font-semibold')
        ->and(tagWith($html, 'wire:key="fm-b"'))->not->toContain('font-semibold')
        // The setting row is exactly what it always was — Blade escapes
        // the quotes inside the attribute, so the pin reads them escaped.
        ->toContain('wire:click="$set(&#039;view&#039;, &#039;b&#039;)"');

    expect(tagWith(renderFilterMenu([
        ['value' => 'a', 'label' => 'Alpha', 'href' => '/alpha'],
        ['value' => 'b', 'label' => 'Beta'],
    ], selected: 'b'), 'wire:key="fm-b"'))->toContain('font-semibold');
});

it('keeps a disabled row off the keyboard, note and all', function () {
    $html = renderFilterMenu([
        ['value' => 'a', 'label' => 'Alpha'],
        ['value' => 'b', 'label' => 'Beta', 'disabled' => true, 'note' => 'No poll yet'],
    ]);

    expect(tagWith($html, 'wire:key="fm-b"'))->toStartWith('<div')
        ->toContain('aria-disabled="true"')
        ->and($html)->toContain('No poll yet');
});

it('reads the first item on the trigger when the selection is unlisted', function () {
    // The reason the group switcher splices the page it is ON into its
    // own list: an unlisted selection would put "All my picks" on a
    // clubhouse's title.
    $html = renderFilterMenu([
        ['value' => 'a', 'label' => 'Alpha', 'href' => '/alpha'],
        ['value' => 'b', 'label' => 'Beta', 'href' => '/beta'],
    ], selected: 'zzz');

    expect((string) str($html)->before('<ui-menu'))->toContain('Alpha')
        ->not->toContain('Beta');
});

it('wears the hero variant as a title that wraps instead of clipping', function () {
    $html = renderFilterMenu([
        ['value' => 'a', 'label' => 'The Rocky Top Rejects Invitational', 'href' => '/alpha'],
    ], variant: 'hero');

    $trigger = (string) str($html)->before('<ui-menu');

    expect($trigger)->toContain('line-clamp-2')
        ->toContain('text-xl')
        ->toContain('The Rocky Top Rejects Invitational')
        // No ring: a ring around a title reads as a button, not a name.
        ->not->toContain('ring-1')
        ->not->toContain('text-sm');

    // And the everyday trigger is untouched by the new variant.
    $default = (string) str(renderFilterMenu([['value' => 'a', 'label' => 'Alpha']]))->before('<ui-menu');

    expect($default)->toContain('text-sm')
        ->not->toContain('line-clamp-2')
        ->not->toContain('text-xl');
});
