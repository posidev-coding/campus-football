@props(['article', 'compact' => false])

{{--
    Links out to ESPN. These are their articles, not ours — we store the
    headline and a thumbnail to make the feed browsable and send the reader to
    the source for the body.
--}}
<a
    href="{{ $article->url }}"
    target="_blank"
    rel="noopener noreferrer"
    {{ $attributes->class(['group flex gap-3 rounded-lg border border-zinc-200 p-3 transition-colors hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700']) }}
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
