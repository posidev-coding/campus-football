<?php

use Illuminate\Support\Facades\Blade;

/*
 * THE THIRD GUTTER VARIANT. `block` divides its row into equal cells and
 * `shrink` sizes to content; neither holds the clubhouse's five stops at
 * 390 — five equal cells clip "Standings" and "Members", and a shrunk
 * track leaves the row ragged. `fill` keeps the full-width track and lets
 * each cell's basis be its own label, so only the spare width is shared
 * and nothing clips as long as the labels' sum fits. Rendered through
 * Blade::render, the way FilterMenuTest reads its component.
 */

function renderGutterTabs(string $variant): string
{
    return Blade::render(
        '<x-gutter-tabs :items="$items" selected="slate" :variant="$variant" model="view" label="Clubhouse" key-prefix="gt" />',
        [
            'items' => ['slate' => 'Slate', 'standings' => 'Standings', 'members' => 'Members', 'invite' => 'Invite', 'talk' => 'Talk'],
            'variant' => $variant,
        ],
    );
}

/** The one tag that carries the needle, from its `<` to its `>`. */
function gutterCellWith(string $html, string $needle): string
{
    $at = strpos($html, $needle);

    expect($at)->not->toBeFalse("no tag carries {$needle}");

    $start = strrpos(substr($html, 0, $at), '<');
    $end = strpos($html, '>', $at);

    return substr($html, $start, $end - $start + 1);
}

it('fills the row with content-sized cells that share only the spare width', function () {
    $html = renderGutterTabs('fill');

    $track = (string) str($html)->before('<button');
    $cell = gutterCellWith($html, 'wire:key="gt-standings"');

    expect($track)->toContain('w-full')
        ->not->toContain('w-max')
        ->and($cell)->toContain('flex-auto')
        ->toContain('min-w-0')
        ->toContain('px-2')
        ->not->toContain('flex-1')
        ->not->toContain('shrink-0')
        // Five stops, all rendered, none dropped to make room.
        ->and(substr_count($html, 'wire:key="gt-'))->toBe(5);
});

it('leaves block and shrink exactly as they were', function () {
    $block = renderGutterTabs('block');
    $shrink = renderGutterTabs('shrink');

    expect((string) str($block)->before('<button'))->toContain('w-full')
        ->and(gutterCellWith($block, 'wire:key="gt-standings"'))->toContain('flex-1')
        ->toContain('px-2')
        ->not->toContain('flex-auto');

    expect((string) str($shrink)->before('<button'))->toContain('w-max')
        ->not->toContain('w-full')
        ->and(gutterCellWith($shrink, 'wire:key="gt-standings"'))->toContain('shrink-0')
        ->toContain('px-3')
        ->not->toContain('flex-auto')
        ->not->toContain('flex-1');
});
