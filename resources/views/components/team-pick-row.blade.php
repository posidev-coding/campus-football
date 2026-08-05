@props(['team'])

{{--
    One searchable-team result: mark, name, and the affordance that says
    tapping it does something.

    Shared by Home's quick-add slot, the onboarding picker and Account's follow
    search, so a team looks the same wherever you are choosing one.

    Takes a plain ARRAY, not a Team model — the picker list is cached, and
    models round-trip through the cache as `__PHP_Incomplete_Class`. That is
    why this renders the two `<img>` tags itself rather than using
    `x-team-logo`, which needs a model.
--}}
<button
    type="button"
    {{ $attributes->class([
        'flex items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2.5 text-left',
        'transition-colors hover:border-blue-400 hover:bg-blue-50',
        'dark:border-zinc-800 dark:hover:border-blue-500 dark:hover:bg-blue-950/40',
    ]) }}
>
    <span class="flex size-8 shrink-0 items-center justify-center">
        @if ($team['logo'] ?? null)
            <img
                src="{{ $team['logo'] }}"
                alt=""
                loading="lazy"
                @class(['size-8 shrink-0 object-contain', 'dark:hidden' => (bool) ($team['logo_dark'] ?? null)])
            >

            @if ($team['logo_dark'] ?? null)
                <img src="{{ $team['logo_dark'] }}" alt="" loading="lazy" class="hidden size-8 shrink-0 object-contain dark:block">
            @endif
        @else
            <span class="size-8 rounded-full bg-zinc-100 dark:bg-zinc-800"></span>
        @endif
    </span>

    {{-- Bolder than a plain list item: these are the one thing on the screen
         the user is here to press. --}}
    <span class="min-w-0 flex-1 truncate font-semibold">{{ $team['name'] }}</span>

    <flux:icon name="plus-circle" variant="mini" class="shrink-0 text-blue-600 dark:text-blue-400" />
</button>
