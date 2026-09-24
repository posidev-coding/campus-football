<?php

use App\Jobs\FetchArticleStory;
use App\Models\Article;
use App\Support\ArticleStory;
use Illuminate\Support\Facades\Cache;
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
 *   - And it is never fetched ON THE RENDER. It used to be, in mount(), and
 *     when ESPN's `now` host sat at its 5s ceiling the page waited out nearly
 *     all of it (CFB-96). The page renders what is stored, and a missing body
 *     is queued as FetchArticleStory from `wire:init`, so only a browser that
 *     runs the page asks for it. A crawler walking every /news/{article} costs
 *     no ESPN requests at all, and that crawl is most of this route's traffic.
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
    /**
     * How long to wait on a queued story before handing the reader the link.
     *
     * A failed fetch writes neither the story nor `story_fetched_at` — no data,
     * not "no body" — so "it landed" can never arrive for it. Without a
     * ceiling the page would poll forever. The player screen's game log has the
     * same one, for the same reason.
     */
    private const WAIT_CEILING = 30;

    /** How long one dispatch answers for every reader of the same story. */
    private const DISPATCH_GUARD_SECONDS = 300;

    public Article $article;

    /**
     * When this page asked for the story. Unix seconds, not Carbon: it rides
     * through Livewire's snapshot.
     */
    public ?int $askedAt = null;

    public function mount(Article $article): void
    {
        $this->article = $article->load('teams:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark');
    }

    /**
     * Queue the body, from `wire:init` — never from mount().
     *
     * One dispatch per article per guard window, however many readers land at
     * once. The job is unique as well, but the guard means a busy story does
     * not even reach the queue's lock.
     */
    public function requestStory(): void
    {
        $this->askedAt = now()->getTimestamp();

        if (! $this->article->storyIsWorthFetching()) {
            return;
        }

        if (Cache::add("article:story:dispatched:{$this->article->id}", true, self::DISPATCH_GUARD_SECONDS)) {
            FetchArticleStory::dispatch($this->article->id);
        }
    }

    /**
     * Is a body plausibly on its way?
     *
     * Only while it has never been asked for. Once `story_fetched_at` is
     * stamped the answer is in, whether or not it is a body. Before
     * `wire:init` has run this is true, which is what a crawler sees: the
     * description and the link, never a false "no body".
     *
     * `$article` is re-read from the database on every request (Livewire
     * holds a model as its key), so a poll sees the job's write unprompted.
     */
    #[Computed]
    public function awaitingStory(): bool
    {
        if (! $this->article->storyIsWorthFetching()) {
            return false;
        }

        return $this->askedAt === null || now()->getTimestamp() - $this->askedAt < self::WAIT_CEILING;
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
    @elseif ($this->awaitingStory)
        {{--
            Never asked for yet, or asked moments ago. `wire:init` queues the
            fetch only once a browser has the page, which is what keeps a
            crawler from spending ESPN requests. The poll reads our own
            database and stops once the body lands or the wait ceiling passes.
            The link is here too: a reader with no JavaScript, or no patience,
            still has the story.
        --}}
        <div
            class="flex flex-col items-start gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
            @if ($askedAt === null) wire:init="requestStory" @endif
            wire:poll.2s.visible
            data-story="pending"
        >
            @if ($article->description)
                <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $article->description }}</p>
            @endif

            <p class="inline-flex items-center gap-2 text-stat text-zinc-500 dark:text-zinc-400">
                <flux:icon.loading class="size-3.5 shrink-0" />
                Loading the story from ESPN…
            </p>

            <flux:button href="{{ $article->url }}" target="_blank" rel="noopener noreferrer" variant="ghost" size="sm">
                Read it on ESPN
            </flux:button>
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
                {{-- Three different answers, and only the middle one is a
                     finding: a fetch that has not come back is not a story
                     without a body. --}}
                {{ match (true) {
                    $article->type === App\Models\Article::MEDIA => 'This one is a video on ESPN rather than a written story.',
                    $article->story_fetched_at !== null => 'ESPN has not published a readable body for this one.',
                    default => 'The story has not come through from ESPN yet.',
                } }}
            </p>

            <flux:button href="{{ $article->url }}" target="_blank" rel="noopener noreferrer" variant="primary" size="sm">
                Open on ESPN
            </flux:button>
        </div>
    @endif
</div>
