{{--
    THE PICKS GRID — everybody's calls, revealed per game. Rows are the
    week's entrants (the viewer first), columns the slate's games; a
    cell is a dash until THAT game kicks off, then the picked side, then
    its grade. Signed points lead the row — a backfired Lock prints its
    real minus.

    A cell prints the school's LOGO, not its abbreviation: at a glance the
    grid is a wall of marks you already know, and a wall of four-letter
    codes is not. The abbreviations stay where they identify the fixture —
    the matchup header — and ride along in each cell as `sr-only` text so
    the column still reads by ear.

    Lives inside `stat-grid`, the one sanctioned wide-table mechanism,
    and this component is the one home of the sticky first column: the
    name cell pins against the CONTAINER's own scroll (never the
    document's) with an explicit background so rows stay named fifteen
    columns deep. Dense-table law throughout.
--}}
@props([
    /** @var array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>} */
    'grid',
])

@php
    /*
     * The grade, worn as LIGHT rather than color. Emerald and red graded
     * the abbreviation when the cell was text; against a school's own
     * mark a second tint has nothing to grade — every logo is already
     * colored, and half of them are already red. So a missed call is
     * dulled instead: desaturated and faded until it reads disabled,
     * which is legible beside any mark and in either mode. An UNGRADED
     * pick, on a game still playing, sits between the two — dimmed but
     * never grayed, because a live pick has not lost anything yet.
     *
     * Light is not the only carrier: `data-tone` states the grade for
     * the tests and an `sr-only` line states it in words for a reader
     * who never sees the mark at all.
     */
    $grading = [
        'win' => '',
        'neutral' => 'opacity-70',
        'loss' => 'opacity-40 grayscale',
    ];

    /* The same grade in words, composed here rather than inline: Blade
       will not read a directive that opens against a word character, so
       `correct@elseif` compiles to nothing and takes the @if with it. */
    $verdict = [
        'win' => ', correct',
        'neutral' => '',
        'loss' => ', wrong',
    ];
@endphp

<div {{ $attributes->class(['overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900']) }}>
    <div class="flex items-baseline justify-between gap-2 border-b border-zinc-100 px-3 py-2 dark:border-zinc-800/60">
        <p class="text-sm font-semibold">Picks</p>
        {{-- The reveal rule, said plainly — fairness, not a bug. --}}
        <p class="text-micro text-zinc-500 dark:text-zinc-400">Picks show at kickoff.</p>
    </div>

    <div class="stat-grid">
        <table class="w-full text-micro whitespace-nowrap">
            <thead>
                <tr class="border-b border-zinc-100 text-zinc-500 dark:border-zinc-800/60">
                    <th scope="col" class="sticky left-0 z-10 bg-white px-3 py-1.5 text-left font-medium dark:bg-zinc-900">Name</th>
                    <th scope="col" class="px-1.5 py-1.5 text-right font-medium">Pts</th>
                    @foreach ($grid['columns'] as $column)
                        <th scope="col" wire:key="grid-col-{{ $column['key'] }}" class="px-1.5 py-1.5 text-center font-medium">
                            <span class="block leading-tight">{{ $column['away'] }}</span>
                            <span class="block leading-tight text-zinc-400">{{ $column['home'] }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($grid['rows'] as $row)
                    <tr
                        wire:key="grid-row-{{ $loop->index }}-{{ $row['name'] }}"
                        @class([
                            'border-b border-zinc-50 last:border-0 dark:border-zinc-800/40',
                            'bg-blue-50/60 dark:bg-blue-950/30' => $row['viewer'],
                        ])
                    >
                        <td @class([
                            'sticky left-0 z-10 max-w-32 truncate px-3 py-1.5 font-medium',
                            'bg-blue-50 dark:bg-blue-950' => $row['viewer'],
                            'bg-white dark:bg-zinc-900' => ! $row['viewer'],
                        ])>
                            @if ($row['viewer'])
                                <span class="sr-only">You — </span>
                            @endif
                            <span class="inline-flex items-center gap-1">
                                @if ($row['icon'] ?? null)
                                    <flux:icon :name="$row['icon']" variant="micro" class="size-3 text-red-500 dark:text-red-400" />
                                @endif
                                {{ $row['name'] }}
                            </span>
                        </td>
                        <td class="tabular px-1.5 py-1.5 text-right font-semibold">{{ $row['points'] }}</td>
                        @foreach ($row['cells'] as $cell)
                            <td
                                wire:key="grid-cell-{{ $loop->parent->index }}-{{ $loop->index }}"
                                data-cell="{{ $cell['state'] }}"
                                data-tone="{{ $cell['tone'] }}"
                                @class([
                                    'px-1.5 py-1.5 text-center',
                                    'text-zinc-300 dark:text-zinc-600' => $cell['state'] !== 'pick',
                                ])
                            >
                                @if ($cell['state'] === 'pick' && ($cell['team'] ?? null) !== null)
                                    <span class="inline-flex items-center justify-center">
                                        <x-team-logo
                                            :team="$cell['team']"
                                            size="xs"
                                            :class="$grading[$cell['tone']] ?? ''"
                                        />
                                        <span class="sr-only">{{ $cell['abbr'].($verdict[$cell['tone']] ?? '') }}</span>
                                    </span>
                                @elseif ($cell['state'] === 'pick')
                                    {{-- A pick on a side neither team on this card fields.
                                         There is no mark to print and there is no stand-in
                                         for one, so the cell says only that it cannot say. --}}
                                    <span aria-label="Pick unavailable">—</span>
                                @elseif ($cell['state'] === 'none')
                                    {{-- A kicked game with no pick is an honest zero. --}}
                                    <span aria-label="No pick">0</span>
                                @else
                                    <span aria-label="Hidden until kickoff">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
