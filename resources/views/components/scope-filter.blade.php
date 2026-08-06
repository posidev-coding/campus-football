@props([
    'year' => null,
    'selected' => 'top25',
    'includeFcs' => false,
    'top25' => true,
])

@php
    /*
     * The WHO filter — Top 25, FBS, FCS, a conference — rendered through the
     * shared filter-menu. This wrapper owns only the domain logic: which
     * options a season offers, and why one might be disabled.
     *
     * Top 25 is offered but DISABLED until the season has a poll, which is the
     * normal state all summer. Greying it out rather than hiding it says the
     * filter exists and is not available yet; leaving it selectable meant the
     * control read "Top 25" while quietly showing all 138 FBS teams.
     */
    $year ??= now()->year;

    $items = array_map(fn (array $option) => [
        'value' => $option['value'],
        'label' => $option['label'],
        'group' => $option['group'],
        'disabled' => $option['disabled'],
        'note' => $option['disabled'] ? 'No poll yet' : null,
    ], App\Support\Scope::options($year, $includeFcs, $top25));
@endphp

<x-filter-menu
    :items="$items"
    :selected="$selected"
    model="scope"
    label="Filter by scope"
    key-prefix="scope"
    {{ $attributes }}
/>
