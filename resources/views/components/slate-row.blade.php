{{--
    ONE SLATE'S STATE, compact — born as My Picks' needs-rows, which
    retired on 2026-09-01 (every card that needed picks rendered there
    twice, as a row and as a card); Home's picks strip is its render
    site now.
    Left: the group and where the entry stands (progress bar, amber
    "Tiebreaker left", emerald "Entry in"). Right: live or the kickoff,
    and a chevron; the whole row walks into the clubhouse.

    `tone` — 'needs' wears the needs-you blue; 'default' stays zinc for
    rows that are done or already playing.
--}}
@props([
    /** @var array<string, mixed> a cards()/PickemPulse card */
    'card',
    'tone' => 'needs',
])

@php
    $group = $card['group'];
    $tiebreakerLeft = ! $card['entryIn']
        && $card['total'] > 0
        && $card['made'] >= $card['total']
        && in_array($card['state'], ['upcoming', 'live'], true);
@endphp

<a
    href="{{ $group->isRoom() ? route('pickem.room', $group) : route('pickem.group', $group) }}"
    wire:navigate
    {{ $attributes->class([
        'flex items-center justify-between gap-3 rounded-xl border px-4 py-3',
        'border-blue-200 bg-blue-50/50 hover:border-blue-300 dark:border-blue-900 dark:bg-blue-950/20 dark:hover:border-blue-800' => $tone === 'needs',
        'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600' => $tone !== 'needs',
    ]) }}
>
    <span class="min-w-0">
        <span class="block truncate font-semibold leading-tight">{{ $group->name }}</span>
        @if ($card['entryIn'])
            <span class="flex items-center gap-1.5 pt-1 text-sm font-medium text-emerald-700 dark:text-emerald-400">
                <flux:icon.check-circle-fill variant="micro" class="size-3.5 shrink-0" />
                Entry in
            </span>
        @elseif ($tiebreakerLeft)
            <span class="block pt-1 text-sm font-medium text-amber-600 dark:text-amber-400">Tiebreaker left</span>
        @else
            <x-slate-progress :made="$card['made']" :total="$card['total']" class="pt-1" />
        @endif
    </span>

    <span class="flex shrink-0 items-center gap-1.5 text-micro text-zinc-500">
        @if ($card['state'] === 'live')
            <x-slate-status status="live" />
        @elseif ($card['firstKick'])
            kicks {{ $card['firstKick']->setTimezone(config('cfb.timezone'))->format('D g:ia') }}
        @endif
        <flux:icon name="chevron-right" variant="micro" class="text-zinc-400" />
    </span>
</a>
