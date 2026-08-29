{{--
    Picks made against the slate's size — the signup-progress grammar (a
    thin bar that says it faster than words), with the count kept beside it
    because "6 of 15" is the number a picker acts on. One continuous fill
    rather than segments: fifteen segments at 390px would be confetti.
--}}
@props([
    'made',
    'total',
    /*
     * `dark` is OPT-IN, for the one surface that sits on a genuinely dark
     * tile — the Woodshed's black, which is black in both schemes. The
     * default is untouched: zinc-500 reads well on a light tint and comes
     * to 3.4:1 on black, which is unreadable exactly where the number is
     * the whole point of the component.
     */
    'tone' => 'default',
])

@php
    $done = $total > 0 && $made >= $total;
    $percent = $total > 0 ? (int) round(min($made, $total) / $total * 100) : 0;
    $onDark = $tone === 'dark';
@endphp

<div {{ $attributes->class(['flex min-w-0 items-center gap-2']) }}>
    <div class="h-1 w-16 overflow-hidden rounded-full sm:w-24 {{ $onDark ? 'bg-white/30' : 'bg-zinc-200 dark:bg-zinc-800' }}" aria-hidden="true">
        <div
            class="h-full rounded-full transition-[width] duration-300 {{ $done ? 'bg-emerald-600 dark:bg-emerald-500' : 'bg-blue-600 dark:bg-blue-500' }}"
            style="width: {{ $percent }}%"
        ></div>
    </div>

    <span class="tabular shrink-0 text-micro font-medium {{ $done ? 'text-emerald-700 dark:text-emerald-400' : ($onDark ? 'text-zinc-300' : 'text-zinc-500') }}">
        {{ $made }} of {{ $total }}
    </span>
</div>
