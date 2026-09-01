{{--
    A standings table for a room — one week's or a season's, decided by the
    columns the caller sends. Dense-table law throughout: every cell
    whitespace-nowrap, the NAME cell `w-full max-w-0 truncate` (min-w-0
    cannot clip a table cell), so a long handle eats itself before the
    numbers ever move.

    `rows` is a list of plain arrays, already ranked and sorted:
      ['rank' => 1, 'user' => User, 'won' => bool, 'cells' => [12, 340]]
    with `headings` naming each cell column in order. Identity is the
    handle when claimed, the name until then — the table never blocks on
    the handle seam. Pass `names` inside a private group to flip that
    around: people who invited each other read names better than handles.

    A row may carry NO user — the Woodshed's Bear sits in standings as
    ['user' => null, 'label' => 'The Bear', 'key' => 'bear', 'icon' =>
    'paw-print', ...]. Label rows never highlight as the viewer and never
    wear the Winner badge (the house's creature has no entry to win with).
--}}
@props([
    'rows' => [],
    /** @var list<string> one label per cells entry, in order */
    'headings' => ['Pts'],
    /** null hides the badge; 'live' | 'prelim' | 'final' shows the room's state. */
    'status' => null,
    'title' => 'Standings',
    /**
     * The viewer's own row when their seat is below the fold — the
     * leaderboard's "You" line: ['rank' => int, 'label' => string,
     * 'cells' => list]. Null renders nothing.
     */
    'pinned' => null,
    /**
     * Print real names instead of handles. TRUE only inside a private
     * group, where everybody was invited by somebody they know and a
     * handle is a worse answer than a name. A public room and the global
     * leaderboard stay on handles: those are strangers, and their legal
     * names are not the room's to publish.
     *
     * Falls back to the handle when a member has no name to print, the
     * mirror of the default's fallback — the table never blocks on
     * either half of the identity seam.
     */
    'names' => false,
])

<div {{ $attributes->class(['overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900']) }}>
    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 px-3 py-2 dark:border-zinc-800/60">
        <p class="text-sm font-semibold">{{ $title }}</p>

        <x-slate-status :status="$status" class="text-micro" />
    </div>

    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-zinc-100 text-micro text-zinc-500 dark:border-zinc-800/60">
                <th scope="col" class="w-8 px-3 py-1.5 text-left font-medium">#</th>
                <th scope="col" class="py-1.5 pe-3 text-left font-medium">Name</th>
                @foreach ($headings as $heading)
                    <th scope="col" class="whitespace-nowrap py-1.5 pe-3 text-right font-medium">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                {{-- A label row may still BE the viewer (the leaderboard
                     precomputes labels): `viewer` overrides the User check. --}}
                @php $viewer = ($row['viewer'] ?? false) || ($row['user'] !== null && $row['user']->id === auth()->id()); @endphp

                <tr
                    wire:key="standing-{{ $row['user']?->id ?? $row['key'] ?? 'label-'.$loop->index }}"
                    @class([
                        'border-b border-zinc-50 last:border-0 dark:border-zinc-800/40',
                        'bg-blue-50/60 dark:bg-blue-950/30' => $viewer,
                    ])
                >
                    <td class="tabular whitespace-nowrap px-3 py-1.5 text-zinc-500">
                        {{ $row['rank'] }}
                        {{-- Movement since the last settled week. Null and
                             zero both render NOTHING — a table full of
                             dashes says less than the silence does. --}}
                        @if (($row['delta'] ?? 0) > 0)
                            <span class="text-micro font-semibold text-emerald-600 dark:text-emerald-400"><span class="sr-only">up </span><span aria-hidden="true">▲</span>{{ $row['delta'] }}</span>
                        @elseif (($row['delta'] ?? 0) < 0)
                            <span class="text-micro font-semibold text-red-600 dark:text-red-400"><span class="sr-only">down </span><span aria-hidden="true">▼</span>{{ abs($row['delta']) }}</span>
                        @endif
                    </td>
                    <td class="w-full max-w-0 truncate py-1.5 pe-3 {{ $viewer ? 'font-semibold' : 'font-medium' }}">
                        @if ($viewer)
                            {{-- The tint is invisible to a screen reader. --}}
                            <span class="sr-only">You — </span>
                        @endif
                        @if ($row['user'] !== null)
                            <span class="inline-flex max-w-full items-center gap-1.5 align-middle">
                                {{-- The member's own colors in the room —
                                     a logo on a neutral puck, never a fill. --}}
                                @if ($row['team'] ?? null)
                                    <x-team-logo :team="$row['team']" size="xs" class="shrink-0" />
                                @endif
                                <span class="truncate">
                                    @if ($names)
                                        {{ $row['user']->name ?: ($row['user']->handle !== null ? '@'.$row['user']->handle : '') }}
                                    @else
                                        {{ $row['user']->handle !== null ? '@'.$row['user']->handle : $row['user']->name }}
                                    @endif
                                </span>
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1">
                                @if ($row['icon'] ?? null)
                                    <flux:icon :name="$row['icon']" variant="micro" class="size-3.5 text-red-500 dark:text-red-400" />
                                @endif
                                {{ $row['label'] ?? '—' }}
                            </span>
                        @endif
                        @if ($row['won'] ?? false)
                            <flux:badge size="sm" color="green" class="ms-1">Winner</flux:badge>
                        @endif
                    </td>
                    @foreach ($row['cells'] as $cell)
                        <td class="tabular whitespace-nowrap py-1.5 pe-3 text-right font-semibold">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach

            {{-- The viewer, pinned when their seat is below the fold. --}}
            @if ($pinned !== null)
                <tr class="border-t-2 border-zinc-200 bg-blue-50/60 dark:border-zinc-700 dark:bg-blue-950/30">
                    <td class="tabular whitespace-nowrap px-3 py-1.5 text-zinc-500">{{ $pinned['rank'] }}</td>
                    <td class="w-full max-w-0 truncate py-1.5 pe-3 font-semibold">{{ $pinned['label'] }}</td>
                    @foreach ($pinned['cells'] as $cell)
                        <td class="tabular whitespace-nowrap py-1.5 pe-3 text-right font-semibold">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>
</div>
