@props(['recruit'])

@php
    $subtext = collect([
        $recruit->position?->abbreviation,
        $recruit->grade ? $recruit->grade.' grade' : null,
        $recruit->high_school,
    ])->filter()->implode(' · ');

    // Built here rather than with inline @if inside an @if/@else, which is
    // what Blade choked on: "unexpected token endif".
    $destination = collect([
        $recruit->committedTeam?->placeName() ?: 'Uncommitted',
        $recruit->hometown(),
    ])->filter()->implode(' · ');

    /*
     * There is no recruit detail page, and none is needed: the recruiting
     * screen's own search resolves a name to that prospect, so the class plus
     * the name is a complete destination.
     */
    $href = route('recruiting', ['year' => $recruit->recruiting_class, 'q' => $recruit->display_name]);
@endphp

<x-search.row :href="$href" :attributes="$attributes">
    {{-- No headshot: ESPN publishes none for high schoolers, so a placeholder
         puck on every row would be noise. The class year does the work an
         avatar does elsewhere — it is the first thing that distinguishes two
         prospects with the same name. --}}
    <span class="tabular flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-micro font-semibold text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
        '{{ substr((string) $recruit->recruiting_class, 2) }}
    </span>

    <span class="min-w-0 flex-1">
        <span class="flex items-center gap-1.5">
            <span class="truncate text-sm">{{ $recruit->display_name }}</span>

            @if ($recruit->national_rank)
                <span class="tabular shrink-0 text-micro text-zinc-400">#{{ $recruit->national_rank }}</span>
            @endif
        </span>

        @if ($subtext !== '')
            <span class="block truncate text-micro text-zinc-500">{{ $subtext }}</span>
        @endif

        {{-- Its own line, like the player row's hometown: it is the first thing
             truncation eats and the row must read right without it. --}}
        <span class="block truncate text-micro text-zinc-400">{{ $destination }}</span>
    </span>
</x-search.row>
