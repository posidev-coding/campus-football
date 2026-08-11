@php
    use App\Support\Navigation;
@endphp

{{--
    The areas, in the desktop header.

    The same level as the phone's bottom tab bar, rendered where a desktop
    sports site expects it. Account is omitted here because the header already
    carries it as its own affordance — the avatar menu — and listing it twice
    in one bar is noise.
--}}
<nav {{ $attributes->class(['items-center gap-0.5']) }} aria-label="Areas">
    @foreach (Navigation::areas() as $area)
        @continue($area['key'] === 'account')

        @php $current = Navigation::isCurrent($area); @endphp

        <a
            href="{{ Navigation::href($area) }}"
            wire:navigate
            @if ($current) aria-current="page" @endif
            {{-- The guided tour's desktop spotlight target — the bottom nav
                 carries the same key below `sm`. --}}
            data-tour="{{ $area['key'] }}"
            @class([
                'rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors',
                'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100' => $current,
                'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-100' => ! $current,
            ])
        >{{ $area['label'] }}</a>
    @endforeach
</nav>
