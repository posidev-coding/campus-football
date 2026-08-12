@props(['article', 'compact' => false])

@php
    /*
     * Read here when there is something to read, out to ESPN when there is not.
     *
     * `isReadable()` is optimistic before the first fetch, because knowing for
     * certain would mean a request per CARD — 50 of them for one feed. The
     * article page absorbs the rare miss: it says what happened and hands over
     * the link, which is exactly where this card would have sent them anyway.
     *
     * `wire:navigate` only on our own links; an external one must be a plain
     * new-tab anchor.
     */
    $readable = $article->isReadable();
@endphp

<a
    href="{{ $readable ? route('article', $article) : $article->url }}"
    @if ($readable) wire:navigate @else target="_blank" rel="noopener noreferrer" @endif
    {{-- `min-w-0` on the card itself rather than at each call site: a grid
         item keeps its min-content width exactly as a flex item does, so a
         long headline inside would widen its track and scroll the document
         sideways. It is a no-op in a block or flex-column parent, and
         load-bearing the moment this card lands in a grid. --}}
    {{ $attributes->class(['group flex min-w-0 gap-3 rounded-lg border border-zinc-200 p-3 transition-colors hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700']) }}
>
    @if ($article->image_url)
        <img
            src="{{ $article->image_url }}"
            alt=""
            loading="lazy"
            class="{{ $compact ? 'size-14' : 'size-20 sm:size-24' }} shrink-0 rounded-md object-cover"
        >
    @endif

    <div class="flex min-w-0 flex-1 flex-col gap-1">
        <h3 class="{{ $compact ? 'text-stat' : 'text-sm' }} font-semibold leading-snug group-hover:underline">
            {{ $article->headline }}
        </h3>

        @unless ($compact)
            @if ($article->description)
                <p class="line-clamp-2 text-stat text-zinc-500">{{ $article->description }}</p>
            @endif
        @endunless

        <div class="mt-auto flex flex-wrap items-center gap-x-2 gap-y-1 text-micro text-zinc-400">
            @if ($article->published_at)
                <span>{{ $article->published_at->diffForHumans() }}</span>
            @endif

            @if ($article->byline)
                <span class="truncate">· {{ $article->byline }}</span>
            @endif

            {{-- ESPN tags a national listicle with every team it mentions, so
                 this is capped rather than rendering 25 chips. --}}
            @foreach ($article->teams->take(3) as $team)
                <span class="rounded bg-zinc-100 px-1 py-px font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    {{ $team->abbreviation ?: $team->short_display_name }}
                </span>
            @endforeach
        </div>
    </div>
</a>
