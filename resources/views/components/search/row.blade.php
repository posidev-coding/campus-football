@props(['href'])

{{--
    The shell every search result shares: one anchor, media on the left, text
    in the middle, chevron on the right. The row IS the link, so nothing
    inside it may be another anchor — x-team-link and friends cannot nest
    here, which is why each row type lays out its own name line.
--}}
<a
    href="{{ $href }}"
    wire:navigate
    {{ $attributes->class(['flex items-center gap-2.5 rounded-lg border border-zinc-200 px-3 py-2 transition-colors hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700']) }}
>
    {{ $slot }}

    <flux:icon name="chevron-right" variant="micro" class="shrink-0 text-zinc-400" />
</a>
