{{--
    A dashed DOOR: the row that promises a place rather than showing
    content — dashed border is the house grammar for "not yet / elsewhere",
    the chevron says it travels. The slot renders under the title, so a
    caller can stack its own sub-lines (a count, a zinger) without this
    shell caring how many.
--}}
@props(['href', 'title', 'navigate' => true])

<a
    href="{{ $href }}"
    @if ($navigate) wire:navigate @endif
    {{ $attributes->class(['flex items-center justify-between gap-3 rounded-xl border border-dashed border-zinc-300 px-4 py-3 transition-colors hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:border-zinc-600 dark:hover:bg-zinc-900']) }}
>
    <span class="min-w-0">
        <span class="block truncate font-semibold leading-tight">{{ $title }}</span>
        {{ $slot }}
    </span>
    <flux:icon name="chevron-right" variant="micro" class="shrink-0 text-zinc-400" />
</a>
