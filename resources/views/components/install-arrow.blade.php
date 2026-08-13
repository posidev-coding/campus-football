{{--
    A pointing cue for the install walkthrough: a bouncing arrow fixed toward
    the browser control the current step names, with a pill label under (or
    over) it. Shown only when the platform being READ is the one detection
    FOUND — an arrow pointing at chrome that is not there would be worse than
    no arrow — and never inside the installed app.

    Must render inside get-app's x-data scope: `platform`, `detected` and
    `standalone` are its state. Decorative by design (`aria-hidden`,
    `pointer-events-none`); the steps themselves carry the instruction.
--}}
@props(['platform', 'at', 'direction', 'label'])

<div
    x-cloak
    x-show="platform === '{{ $platform }}' && detected === '{{ $platform }}' && ! standalone"
    wire:key="cue-{{ $platform }}"
    aria-hidden="true"
    class="pointer-events-none fixed z-50 flex flex-col items-center gap-1 sm:hidden {{ $at }}"
>
    @if ($direction === 'up')
        <flux:icon.arrow-up class="size-5 text-blue-600 motion-safe:animate-bounce dark:text-blue-400" />
    @endif

    <span class="rounded-md bg-zinc-900/90 px-2 py-1 text-micro font-medium text-white dark:bg-zinc-100/90 dark:text-zinc-900">
        {{ $label }}
    </span>

    @if ($direction === 'down')
        <flux:icon.arrow-down class="size-5 text-blue-600 motion-safe:animate-bounce dark:text-blue-400" />
    @endif
</div>
