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
    the handle seam.

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
])

<div {{ $attributes->class(['overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900']) }}>
    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 px-3 py-2 dark:border-zinc-800/60">
        <p class="text-sm font-semibold">{{ $title }}</p>

        @if ($status === 'live')
            <span class="flex shrink-0 items-center gap-1 text-micro font-semibold text-red-600 dark:text-red-400">
                <x-live-dot />
                Live
            </span>
        @elseif ($status === 'prelim')
            <flux:badge size="sm" color="amber">Preliminary</flux:badge>
        @elseif ($status === 'final')
            <flux:badge size="sm" color="green">Final</flux:badge>
        @endif
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
                @php $viewer = $row['user'] !== null && $row['user']->id === auth()->id(); @endphp

                <tr
                    wire:key="standing-{{ $row['user']?->id ?? $row['key'] ?? 'label-'.$loop->index }}"
                    @class([
                        'border-b border-zinc-50 last:border-0 dark:border-zinc-800/40',
                        'bg-blue-50/60 dark:bg-blue-950/30' => $viewer,
                    ])
                >
                    <td class="tabular whitespace-nowrap px-3 py-1.5 text-zinc-500">{{ $row['rank'] }}</td>
                    <td class="w-full max-w-0 truncate py-1.5 pe-3 {{ $viewer ? 'font-semibold' : 'font-medium' }}">
                        @if ($row['user'] !== null)
                            {{ $row['user']->handle !== null ? '@'.$row['user']->handle : $row['user']->name }}
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
        </tbody>
    </table>
</div>
