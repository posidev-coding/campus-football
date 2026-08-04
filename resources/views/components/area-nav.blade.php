@php
    use App\Support\Navigation;
@endphp

{{--
    The areas, in the desktop header.

    The same level as the phone's bottom tab bar, rendered where a desktop
    sports site expects it. Search and Account are omitted here because the
    header already carries both as their own affordances — the ⌘K trigger and
    the avatar menu — and listing them twice in one bar is noise.
--}}
<nav {{ $attributes->class(['items-center gap-0.5']) }} aria-label="Areas">
    @foreach (Navigation::areas() as $area)
        @continue(in_array($area['key'], ['search', 'account'], true))

        @php $current = Navigation::isCurrent($area); @endphp

        <a
            href="{{ Navigation::href($area) }}"
            wire:navigate
            @if ($current) aria-current="page" @endif
            @class([
                'rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors',
                'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100' => $current,
                'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-100' => ! $current,
            ])
        >{{ $area['label'] }}</a>
    @endforeach
</nav>
