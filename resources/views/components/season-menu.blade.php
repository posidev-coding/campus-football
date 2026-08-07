@props([
    /** list<int|string> */
    'years' => [],
    'selected' => null,
    'model' => 'year',
    /** The accessible name — the visible text is only the year itself. */
    'label' => 'Season',
    /** `accent` when this rides a team's branded hero. See x-filter-menu. */
    'variant' => 'default',
])

@php
    /*
     * The WHEN control — season, recruiting class — as a text-button
     * dropdown, so it speaks the same language as every other filter instead
     * of being the one boxed select on the row. Always the LAST control on
     * its row, which is why it aligns its menu to the end.
     */
    $items = collect($years)
        ->map(fn ($year) => ['value' => (string) $year, 'label' => (string) $year])
        ->all();
@endphp

<x-filter-menu
    :items="$items"
    :selected="(string) $selected"
    :model="$model"
    :label="$label"
    :variant="$variant"
    key-prefix="season"
    align="end"
    {{ $attributes }}
/>
