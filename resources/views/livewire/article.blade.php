<?php

use App\Models\Article;
use App\Services\Espn\Sync\SyncArticleStory;
use App\Support\ArticleStory;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * One article, read here rather than on espn.com.
 *
 * This is the second screen in the app that can cause an ESPN request, and it
 * borrows the game page's constraints wholesale, because the shape is the same:
 * a body exists in exactly one payload, and it cannot change once published.
 *
 *   - Fetched ONCE, ever. A stored story makes every later view a pure database
 *     read, so an article that gets shared costs one request no matter how many
 *     people open it.
 *   - A miss is throttled per ARTICLE, not per viewer (see SyncArticleStory).
 *
 * A third of articles are `Media` — ESPN video and photo posts with no body at
 * all — so this screen must read well with nothing to render. It says so and
 * offers the link, rather than showing an empty page or bouncing the reader
 * somewhere they did not ask to go.
 *
 * This is a League screen and therefore PURE: no Voice, no jokes. Someone
 * reading a news story wants the story.
 */
new class extends Component
{
    public Article $article;

    public function mount(Article $article): void
    {
        $this->article = $article->load('teams:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark');

        app(SyncArticleStory::class)->fill($this->article);
    }

    /**
     * The sanitized body.
     *
     * Rendering is memoized on the stored story, so the DOM parse happens once
     * per article rather than once per view — and improving the renderer does
     * not mean re-fetching anything, because what is stored is ESPN's raw
     * markup rather than our rendering of it.
     */
    #[Computed]
    public function body(): string
    {
        return ArticleStory::cached(
            $this->article->id,
            $this->article->story,
            $this->article->story_images ?? [],
        );
    }
}; ?>

<div class="flex flex-col gap-5">
    {{-- No visible h1 anywhere else in the app, but this one earns it: the
         section strip names the SCREEN, and here the screen is one specific
         story. The strip says "News"; only the headline says which. --}}
    <header class="flex flex-col gap-3">
        <flux:heading size="xl" level="1" class="leading-tight">
            {{ $article->headline }}
        </flux:heading>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-stat text-zinc-500 dark:text-zinc-400">
            @if ($article->byline)
                <span>{{ $article->byline }}</span>
            @endif

            @if ($article->published_at)
                <span class="text-zinc-400 dark:text-zinc-500">
                    @if ($article->byline) · @endif
                    {{ $article->published_at->diffForHumans() }}
                </span>
            @endif

            {{-- Attribution, not decoration. The words are ESPN's; saying so
                 plainly is the least a reader is owed, and it sits with the
                 byline rather than buried at the foot of the page. --}}
            <span class="text-zinc-400 dark:text-zinc-500">· ESPN</span>
        </div>

        @if ($article->teams->isNotEmpty())
            <div class="flex flex-wrap gap-1.5">
                @foreach ($article->teams->take(4) as $team)
                    <a
                        href="{{ route('team', $team) }}"
                        wire:navigate
                        class="rounded bg-zinc-100 px-1.5 py-0.5 text-micro font-medium text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                    >
                        {{ $team->abbreviation ?: $team->short_display_name }}
                    </a>
                @endforeach
            </div>
        @endif
    </header>

    @if ($article->image_url)
        {{-- `-mx-4` so the lead image reaches both screen edges at 390px, the
             same trick the scoreboard chrome uses, and returns to the column
             from `sm` where the page is no longer the full width. --}}
        <img
            src="{{ $article->image_url }}"
            alt=""
            class="-mx-4 aspect-video w-[calc(100%+2rem)] max-w-none object-cover sm:mx-0 sm:w-full sm:rounded-lg"
        >
    @endif

    @if ($this->body !== '')
        {{-- `prose` is not available here, so the story's own tags are styled
             through a scoped block in app.css. Rendered unescaped, which is
             only safe because ArticleStory runs an allowlist over it. --}}
        {{-- `lg:mx-auto` centres the measure in the column. The body caps
             itself at 68ch (~590px) in app.css, so on a wide screen it
             otherwise sat hard left with a few hundred pixels of nothing to
             its right — which reads as a broken layout rather than as a
             deliberate reading width. --}}
        <div class="article-body lg:mx-auto">{!! $this->body !!}</div>

        <div class="flex flex-col gap-2 border-t border-zinc-200 pt-4 text-stat text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
            <span>Story by ESPN{{ $article->byline ? ', '.$article->byline : '' }}.</span>

            <a
                href="{{ $article->url }}"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex w-fit items-center gap-1 font-medium text-blue-600 hover:underline dark:text-blue-400"
            >
                Read it on ESPN
                <flux:icon name="arrow-up-right" variant="micro" />
            </a>
        </div>
    @else
        {{--
            No body: a video or photo post, or the rare story ESPN serves us
            nothing for. Both get the same honest screen — say what it is, and
            hand over the link. Redirecting instead would take a reader
            somewhere they did not choose to go.
        --}}
        <div class="flex flex-col items-start gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
            @if ($article->description)
                <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $article->description }}</p>
            @endif

            <p class="text-stat text-zinc-500 dark:text-zinc-400">
                {{ $article->type === App\Models\Article::MEDIA
                    ? 'This one is a video on ESPN rather than a written story.'
                    : 'ESPN has not published a readable body for this one.' }}
            </p>

            <flux:button href="{{ $article->url }}" target="_blank" rel="noopener noreferrer" variant="primary" size="sm">
                Open on ESPN
            </flux:button>
        </div>
    @endif
</div>
