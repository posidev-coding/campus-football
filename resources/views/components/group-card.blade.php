{{--
    ONE OF YOUR CONTESTS — a group or a joined room as a
    whole-card door to its clubhouse, wearing its mode's mark and colors
    (the identity seam) with the week's state on the second row.

    `card` is the lobby's cards() array shape: group, contest,
    commissioner, state (waiting | upcoming | live | prelim | final),
    made/total, points, won, wins, firstKick, deadline. The five-way state
    row moved here wholesale from pass 2's inline markup — same states,
    same words, now beside an identity a thumb can find in a stack.
--}}
@props([
    /** @var array<string, mixed> */
    'card',
])

@php
    $group = $card['group'];
    $mode = $card['contest']?->mode;
    $palette = $mode?->palette();
    $members = $group->memberships_count ?? null;
@endphp

<a
    href="{{ $group->isRoom() ? route('pickem.room', $group) : route('pickem.group', $group) }}"
    wire:navigate
    {{ $attributes->class(['flex flex-col gap-2 rounded-xl border border-zinc-200 px-4 py-3 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600']) }}
>
    <div class="flex items-center justify-between gap-3">
        <span class="flex min-w-0 items-center gap-2.5">
            @if ($mode !== null)
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg border {{ $palette['tile'] }}">
                    <flux:icon :name="$mode->icon()" variant="micro" class="size-4 {{ $palette['icon'] }}" />
                </span>
            @endif

            <span class="min-w-0">
                <span class="block truncate font-semibold leading-tight">{{ $group->name }}</span>
                @if ($members !== null)
                    <span class="block text-micro text-zinc-500">{{ $members }} {{ Str::plural('member', $members) }}</span>
                @endif
            </span>
        </span>

        <span class="flex shrink-0 items-center gap-1.5">
            @if ($card['wins'] > 0)
                <flux:badge size="sm" color="green">{{ $card['wins'] }} {{ Str::plural('win', $card['wins']) }}</flux:badge>
            @endif
            @if ($mode !== null)
                <span class="rounded-full px-2 py-0.5 text-micro font-semibold {{ $palette['chip'] }}">{{ $mode->label() }}</span>
            @endif
        </span>
    </div>

    <div class="flex items-center justify-between gap-3 text-sm">
        @if ($card['state'] === 'waiting')
            @if ($card['commissioner'] && $card['contest'])
                <span class="font-medium text-blue-600 dark:text-blue-400">Build the slate</span>
                @if ($card['deadline'])
                    <span class="shrink-0 text-micro text-zinc-500">due {{ $card['deadline']->format('D g:ia') }}</span>
                @endif
            @else
                <span class="min-w-0 truncate text-zinc-500 dark:text-zinc-400">{{ App\Support\Voice::line('group.slate.waiting') }}</span>
            @endif
        @elseif ($card['state'] === 'upcoming')
            <x-slate-progress :made="$card['made']" :total="$card['total']" />
            @if ($card['firstKick'])
                <span class="shrink-0 text-micro text-zinc-500">
                    kicks {{ $card['firstKick']->setTimezone(config('cfb.timezone'))->format('D g:ia') }}
                </span>
            @endif
        @elseif ($card['state'] === 'live')
            <x-slate-status status="live" />
            <span class="tabular shrink-0 font-semibold">{{ $card['points'] }} pts</span>
        @elseif ($card['state'] === 'prelim')
            <x-slate-status status="prelim" />
            <span class="tabular shrink-0 font-semibold">{{ $card['points'] }} pts</span>
        @else
            <span class="flex items-center gap-1.5">
                <x-slate-status status="final" />
                @if ($card['won'])
                    <flux:badge size="sm" color="green">Winner</flux:badge>
                @endif
            </span>
            <span class="tabular shrink-0 font-semibold">{{ $card['points'] }} pts</span>
        @endif
    </div>
</a>
