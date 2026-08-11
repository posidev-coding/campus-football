@props(['steps' => []])

{{--
    One platform's install walkthrough: numbered, iconed, in a quiet card. The
    step text arrives as trusted template HTML (the <strong> around the OS's
    own labels), never as user input.
--}}
<ol class="flex flex-col gap-3 rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-800">
    @foreach ($steps as $index => $step)
        <li class="flex items-start gap-3" wire:key="step-{{ $index }}">
            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-micro font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">
                {{ $index + 1 }}
            </span>

            <span class="flex min-w-0 items-start gap-2 pt-0.5 text-sm text-zinc-700 dark:text-zinc-300">
                <flux:icon :name="$step['icon']" variant="mini" class="mt-px shrink-0 text-zinc-500 dark:text-zinc-400" />
                <span>{!! $step['text'] !!}</span>
            </span>
        </li>
    @endforeach
</ol>
