{{--
    HOW ONE MODE IS PLAYED — an expandable card in the lobby's rules zone:
    the identity row as the disclosure button, the enum's ruleLines() as
    the payload. The rules are instructions and stay plain product
    vocabulary — ContestMode::ruleLines() is the ONE source this card, the
    mode doors, the join landing and the docs all read, so the mode can
    never be described two ways.

    Collapsed content is x-show, not removed — a test drives the reactive
    end state by asserting the lines are in the DOM.
--}}
@props([
    /** @var App\Enums\ContestMode */
    'mode',
    'open' => false,
])

@php $palette = $mode->palette(); @endphp

<div
    x-data="{ open: @js($open) }"
    {{ $attributes->class(['overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700']) }}
>
    <button
        type="button"
        x-on:click="open = ! open"
        {{-- Server-rendered initial state, Alpine keeps it true after: a
             reader before (or without) JS still hears a real disclosure. --}}
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        x-bind:aria-expanded="open"
        aria-controls="mode-rules-{{ $mode->value }}"
        class="focus-ring flex w-full items-center gap-3 p-4 text-start"
    >
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg border {{ $palette['tile'] }}">
            <flux:icon :name="$mode->icon()" variant="mini" class="{{ $palette['icon'] }}" />
        </span>

        <span class="min-w-0 flex-1">
            <span class="block font-bold leading-tight">{{ $mode->label() }}</span>
            <span class="block truncate pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $mode->blurb() }}</span>
        </span>

        <flux:icon name="chevron-down" variant="micro" class="shrink-0 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-180'" />
    </button>

    <div id="mode-rules-{{ $mode->value }}" x-show="open" x-cloak class="border-t border-zinc-100 px-4 py-3 dark:border-zinc-800/60">
        <ul class="flex flex-col gap-1.5">
            @foreach ($mode->ruleLines() as $line)
                <li wire:key="rule-{{ $mode->value }}-{{ $loop->index }}" class="flex gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                    <span class="mt-1.5 size-1.5 shrink-0 rounded-full {{ $palette['icon'] }} bg-current opacity-60" aria-hidden="true"></span>
                    <span class="min-w-0">{{ $line }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</div>
