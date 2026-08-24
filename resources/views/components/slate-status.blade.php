{{--
    One slate's state, said the same way everywhere: the live pulse with
    its word, the amber Preliminary, the green Final — and, where a
    surface sells the week before it starts, the upcoming label the caller
    names. Attributes land on the live label (the one branch with a box of
    its own); the badges are flux's.
--}}
@props([
    /** 'live' | 'prelim' | 'final' | 'upcoming' | null (renders nothing) */
    'status',
    /** The upcoming badge's label, or null to render nothing before kickoff. */
    'upcoming' => null,
])

@if ($status === 'live')
    <span {{ $attributes->class(['flex shrink-0 items-center gap-1.5 font-semibold text-red-600 dark:text-red-400']) }}>
        <x-live-dot />
        Live
    </span>
@elseif ($status === 'prelim')
    <flux:badge size="sm" color="amber">Preliminary</flux:badge>
@elseif ($status === 'final')
    <flux:badge size="sm" color="green">Final</flux:badge>
@elseif ($status === 'upcoming' && $upcoming !== null)
    <flux:badge size="sm" color="green">{{ $upcoming }}</flux:badge>
@endif
