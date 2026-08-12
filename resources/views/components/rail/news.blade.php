@props([
    'limit' => 5,
])

@php
    use App\Models\Article;
    use App\Support\Remember;

    /*
     * National headlines, never team-filtered. Article::scopeMentioning exists
     * but its own docblock is the warning: a Top 25 preview tags twenty-five
     * teams, so tag-matching surfaces the same handful of listicles beside
     * every team in the country.
     *
     * 300s rather than 900: a headline going stale is more visible than a
     * poll doing the same. Ids only in the cache — a model round-trips
     * through Redis as __PHP_Incomplete_Class and fails on the SECOND
     * request, never the first.
     */
    $ids = Remember::filled("rail:news:{$limit}", 300, fn () => Article::query()
        ->newest()
        ->limit($limit)
        ->pluck('id')
        ->all());

    /*
     * `story`, `story_fetched_at` and `type` are not decoration: isReadable()
     * reads all three to decide whether a headline links inward or out to
     * espn.com, and a constrained select missing one of them is a 500 (lazy
     * loading is off) rather than a wrong link.
     */
    $articles = $ids === []
        ? collect()
        : Article::whereIn('id', $ids)
            ->newest()
            ->get(['id', 'headline', 'url', 'published_at', 'story', 'story_fetched_at', 'type'])
            ->all();
@endphp

@if ($articles !== [])
    <section {{ $attributes->class(['flex shrink-0 flex-col rounded-lg border border-zinc-200 dark:border-zinc-800']) }}>
        <header class="flex items-baseline justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
            <h2 class="text-sm font-semibold">Latest news</h2>
            <a href="{{ route('news') }}" wire:navigate
               class="shrink-0 text-micro text-zinc-500 hover:text-zinc-900 hover:underline dark:hover:text-zinc-100">
                All news
            </a>
        </header>

        <ol class="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800/60">
            @foreach ($articles as $article)
                @php $readable = $article->isReadable(); @endphp

                <li wire:key="rail-news-{{ $article->id }}">
                    {{-- Readable stories open in the app; the rest go to ESPN,
                         which is the same rule the article cards use. --}}
                    <a
                        href="{{ $readable ? route('article', $article) : $article->url }}"
                        @if ($readable) wire:navigate @else target="_blank" rel="noopener noreferrer" @endif
                        class="flex flex-col gap-0.5 px-3 py-2 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-900"
                    >
                        <span class="line-clamp-2 text-stat font-medium">{{ $article->headline }}</span>

                        @if ($article->published_at)
                            <span class="text-micro text-zinc-400">{{ $article->published_at->diffForHumans() }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ol>
    </section>
@endif
