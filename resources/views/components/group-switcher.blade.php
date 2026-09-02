{{--
    THE GROUP SWITCHER — which of your seats you are looking at, and one
    tap to any other. Sectioned the way the product is: the overview,
    then MY GROUPS (season-long, private), then WEEK N CONTESTS (this
    Saturday's public rooms you sit in, the always-open tables, and the
    door to the Lobby, which is where a room is JOINED — this lists what
    you hold, never what is for sale).

    THE OVERVIEW'S NAME (2026-09-01): the trigger reads "My groups and
    rooms" — both container nouns, the possession, and no third naming of
    "My Picks" (the chip above already says that). Its menu row reads
    "All my groups and rooms", because the row sits directly above the
    "My Groups" section heading and has to read as everything. Sentence
    case on purpose: title-cased "My Groups" is the section heading below,
    and the capital G is what keeps the two strings apart in an ordered
    assertion. "All my picks" died at this one source.

    Pure navigation. Every row carries `href` and the menu holds no
    Livewire state at all: it is an x-filter-menu — the house's one
    dropdown idiom — whose items go somewhere instead of setting
    something. Nothing here is named `$scope`; that word is the League's.

    ONE READ. `seats` arrives from the host screen's own Seats computed,
    the same read My Picks' cards() stands on, so the menu and the
    sections under it can never list a different set of groups.

    The page you are ON is always on the trigger. A lobby the reader is
    previewing without a seat, or a room whose Saturday is played, is in
    none of the lists — so it is spliced in as a bare row rather than
    letting the menu fall back to its first item and title a clubhouse
    "My groups and rooms".

    One variant on both screens since pass 2: `hero` — title weight,
    start-aligned, clamped to two lines — is the clubhouse title on the
    group hero's band AND the overview's first row above the fork. The
    switcher IS the screen's name on both, which is why it never sat in
    the plate's actions slot (that silences the name and makes the
    switcher test slice vacuous). `default` stays for any other caller.
--}}
@props([
    /** @var \App\Support\Seats every seat the viewer holds, read once by the host */
    'seats',
    /** @var \App\Models\Group|null the group the reader is standing in; null on /picks */
    'current' => null,
    /** `hero` on both screens — the title; `default` for any other caller. */
    'variant' => 'default',
])

@php
    $door = fn (App\Models\Group $group) => $group->isRoom()
        ? route('pickem.room', $group)
        : route('pickem.group', $group);

    // `mark` is the group itself: the menu row wears the same picture the
    // card and the hero wear (uploaded icon, conference shield, initials).
    $row = fn (App\Models\Group $group, ?string $section = null, ?string $note = null) => [
        'value' => 'g-'.$group->id,
        'label' => $group->name,
        'href' => $door($group),
        'group' => $section,
        'note' => $note,
        'mark' => $group,
    ];

    // A null week label means the calendar has no week: the rooms are
    // all past by then, and the tables and the Lobby row render BARE.
    // Never a substituted week.
    $contests = $seats->weekLabel() === null ? null : $seats->weekLabel().' Contests';
    $open = $seats->openCount();

    $items = [
        ['value' => 'all', 'label' => 'My groups and rooms', 'menuLabel' => 'All my groups and rooms', 'href' => route('pickem.home')],
        ...$seats->privateGroups()->map(fn ($group) => $row($group, 'My Groups'))->all(),
        ...$seats->rooms()->map(fn ($group) => $row($group, $contests))->all(),
        ...$seats->tables()->map(fn ($group) => $row($group, $contests, 'Always open'))->all(),
        // "{n} open", never the door's own sentence — My Picks counts
        // that one exactly once on the screen.
        [
            'value' => 'lobby',
            'label' => 'Browse the Lobby',
            'href' => route('pickem.lobby'),
            'group' => $contests,
            'note' => $open > 0 ? $open.' open' : null,
        ],
    ];

    $selected = $current === null ? 'all' : 'g-'.$current->id;

    if ($current !== null && collect($items)->firstWhere('value', $selected) === null) {
        array_splice($items, 1, 0, [$row($current)]);
    }
@endphp

<x-filter-menu
    :items="$items"
    :selected="$selected"
    :variant="$variant"
    label="Switch group"
    key-prefix="switch"
    data-group-switcher="true"
    {{ $attributes }}
/>
