{{--
    The one notice row: a short server-set answer to something the reader
    just did, rendered NEAR the control that produced it — never parked at
    the top of a page the tap can no longer see.

    `tone` says what kind of answer it is, because the class of bug this
    retires is a refusal dressed in a green success box. `role="status"
    aria-live="polite"` announces the change to a screen reader without
    stealing focus — these were the app's first live regions, so keep the
    grammar here rather than hand-rolling one elsewhere.
--}}
@props(['tone' => 'neutral'])

<div
    role="status"
    aria-live="polite"
    {{ $attributes->class([
        'rounded-xl border px-4 py-3 text-sm',
        match ($tone) {
            'success' => 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200',
            'error' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200',
            default => 'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-200',
        },
    ]) }}
>
    {{ $slot }}
</div>
