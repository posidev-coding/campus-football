{{--
    A group label inside the command palette.

    Flux's command component has no group primitive, so results are separated by
    a plain heading. It is not a `flux:command.item`, which matters: the palette
    keyboard-navigates between items, and a focusable heading would make the
    arrow keys land on something you cannot select.
--}}
<div {{ $attributes->class(['px-2 pb-1 pt-2 text-micro font-semibold uppercase tracking-wide text-zinc-400 first:pt-0 dark:text-zinc-500']) }}>
    {{ $slot }}
</div>
