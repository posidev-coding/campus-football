{{--
    The install pitch on Home — reinforcement after the tour's own install
    stop, never the opener: Home renders it only for members whose tour has
    finished, and guests never see it. One slim row on purpose; the app's
    content outranks the shell's pitch.

    Dismissal is $persist to localStorage, NAMESPACED BY USER ID: install
    state is a property of the DEVICE — the same person on a new phone should
    hear the pitch again — and the id keeps two people sharing one phone from
    answering for each other. No table, no cookie; the device remembers.
    `data-install-only` removes it inside the installed app by stylesheet, so
    it cannot flash before Alpine boots.
--}}
<div
    x-data="{ dismissed: $persist(false).as('cfb.install.dismissed.' + {{ auth()->id() ?? "'guest'" }}) }"
    x-show="! dismissed"
    x-cloak
    data-install-only
    {{ $attributes->class([
        'flex items-center gap-3 rounded-xl bg-zinc-50 px-4 py-2.5 ring-1 ring-zinc-200',
        'dark:bg-zinc-900 dark:ring-zinc-800',
    ]) }}
>
    <flux:icon.phone class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400" variant="mini" />

    <p class="min-w-0 flex-1 truncate text-sm">
        <span class="font-medium">{{ App\Support\Voice::line('install.banner.heading') }}</span>
        <span class="hidden text-zinc-500 sm:inline dark:text-zinc-400">{{ App\Support\Voice::line('install.banner.body') }}</span>
    </p>

    <flux:button :href="route('get-app')" wire:navigate variant="filled" size="xs" class="shrink-0">
        Show me how
    </flux:button>

    <flux:button
        x-on:click="dismissed = true"
        size="xs"
        square
        variant="ghost"
        icon="x-mark"
        class="shrink-0"
        aria-label="Dismiss"
    />
</div>
