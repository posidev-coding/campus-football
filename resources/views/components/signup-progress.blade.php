{{--
    The registration progress bar: three thin segments that fill as the reader
    answers, sharp and wordless — the whole pitch is "easy as 1-2-3", and a
    bar one-third full says that faster than "Step 1 of 3" ever did. The text
    survives as sr-only, which is also what the tests read.

    A bespoke strip rather than shared chrome because no existing vocabulary
    piece is a progress indicator; it scrolls nothing and selects nothing, so
    ChromeConsistency has no opinion.
--}}
@props(['step', 'total'])

<div {{ $attributes->class(['flex w-24 items-center gap-1']) }}>
    <span class="sr-only">Step {{ $step }} of {{ $total }}</span>

    @for ($i = 1; $i <= $total; $i++)
        <span
            aria-hidden="true"
            wire:key="progress-{{ $i }}"
            class="h-1 flex-1 rounded-full transition-colors duration-300 {{ $i <= $step ? 'bg-blue-600 dark:bg-blue-500' : 'bg-zinc-200 dark:bg-zinc-800' }}"
        ></span>
    @endfor
</div>
