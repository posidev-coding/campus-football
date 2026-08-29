{{--
    The two-logo score variant of record-heading, for a game.

    A game has no single identity to put a name and an avatar against — it is
    two teams and a number — so it gets its own partial rather than bending the
    shared one into a shape that reads badly for everything else.

    A logo never sits on a team's own color: both ride the same neutral puck,
    which is the app-wide rule and the reason this is not tinted.
--}}
@props([
    'homeLogo' => null,
    'awayLogo' => null,
    'homeName',
    'awayName',
    'score' => null,
    'badges' => [],
    'meta' => [],
])
<div class="flex flex-col gap-2">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span class="inline-flex items-center gap-2">
            @if ($awayLogo)
                <img src="{{ $awayLogo }}" alt="" class="h-9 w-9 rounded-lg object-contain" />
            @endif
            <span class="text-xl font-bold tracking-tight">{{ $awayName }}</span>
        </span>

        <span class="text-lg font-semibold text-gray-400 dark:text-gray-500">
            {{ $score ?? 'at' }}
        </span>

        <span class="inline-flex items-center gap-2">
            @if ($homeLogo)
                <img src="{{ $homeLogo }}" alt="" class="h-9 w-9 rounded-lg object-contain" />
            @endif
            <span class="text-xl font-bold tracking-tight">{{ $homeName }}</span>
        </span>

        @foreach ($badges as $badge)
            <x-filament::badge :color="$badge['color'] ?? 'gray'">{{ $badge['label'] }}</x-filament::badge>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
        @foreach ($meta as $item)
            <span class="inline-flex items-center gap-1">
                <x-filament::icon :icon="$item['icon']" class="h-4 w-4" />{{ $item['label'] }}
            </span>
        @endforeach
    </div>
</div>
