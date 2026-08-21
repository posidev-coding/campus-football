{{--
    Picks made against the slate's size — the signup-progress grammar (a
    thin bar that says it faster than words), with the count kept beside it
    because "6 of 15" is the number a picker acts on. One continuous fill
    rather than segments: fifteen segments at 390px would be confetti.
--}}
@props(['made', 'total'])

@php
    $done = $total > 0 && $made >= $total;
    $percent = $total > 0 ? (int) round(min($made, $total) / $total * 100) : 0;
@endphp

<div {{ $attributes->class(['flex min-w-0 items-center gap-2']) }}>
    <div class="h-1 w-16 overflow-hidden rounded-full bg-zinc-200 sm:w-24 dark:bg-zinc-800" aria-hidden="true">
        <div
            class="h-full rounded-full transition-[width] duration-300 {{ $done ? 'bg-emerald-600 dark:bg-emerald-500' : 'bg-blue-600 dark:bg-blue-500' }}"
            style="width: {{ $percent }}%"
        ></div>
    </div>

    <span class="tabular shrink-0 text-micro font-medium {{ $done ? 'text-emerald-700 dark:text-emerald-400' : 'text-zinc-500' }}">
        {{ $made }} of {{ $total }}
    </span>
</div>
